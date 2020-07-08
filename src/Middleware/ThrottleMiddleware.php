<?php
declare(strict_types=1);

namespace Muffin\Throttle\Middleware;

use Cake\Cache\Cache;
use Cake\Core\InstanceConfigTrait;
use Cake\Http\Response;
use Closure;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ThrottleMiddleware implements MiddlewareInterface
{
    use InstanceConfigTrait;

    /**
     * Default config.
     *
     * @var array
     */
    protected $_defaultConfig = [
        'response' => [
            'body' => 'Rate limit exceeded',
            'type' => 'text/html',
            'headers' => [],
        ],
        'interval' => '+1 minute',
        'limit' => 10,
        'headers' => [
            'limit' => 'X-RateLimit-Limit',
            'remaining' => 'X-RateLimit-Remaining',
            'reset' => 'X-RateLimit-Reset',
        ],
        'requestWeight' => 1,
    ];

    /**
     * Cache configuration name
     *
     * @var string
     */
    public static $cacheConfig = 'throttle';

    /**
     * Cache expiration key suffix
     *
     * @var string
     */
    public static $cacheExpirationSuffix = 'expires';

    /**
     * Unique client identifier
     *
     * @var string
     */
    protected $_identifier;

    /**
     * Number of connections after increment
     *
     * @var int
     */
    protected $_count;

    /**
     * ThrottleMiddleware constructor.
     *
     * @param array $config Configuration options
     */
    public function __construct(array $config = [])
    {
        $config += ['identifier' => function ($request) {
            return $request->clientIp();
        }];

        $this->setConfig($config);
    }

    /**
     * Called when the class is used as a function
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler The request handler.
     * @return \Psr\Http\Message\ResponseInterface A response.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->_setIdentifier($request);
        $this->_initCache();
        $this->_count = $this->_touch($request);

        $config = $this->getConfig();

        if ($this->_count > $config['limit']) {
            $response = new Response();

            if (is_array($config['response']['headers'])) {
                foreach ($config['response']['headers'] as $name => $value) {
                    $response = $response->withHeader($name, $value);
                }
            }

            if (isset($config['message'])) {
                $message = $config['message'];
            } else {
                $message = $config['response']['body'];
            }

            return $response
                ->withStatus(429)
                ->withType($config['response']['type'])
                ->withStringBody($message);
        }

        $response = $handler->handle($request);

        return $this->_setHeaders($response);
    }

    /**
     * Sets the identifier class property. Uses Throttle default IP address
     * based identifier unless a callable alternative is passed.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request RequestInterface instance
     * @return void
     * @throws \InvalidArgumentException
     */
    protected function _setIdentifier(ServerRequestInterface $request): void
    {
        $closure = $this->getConfig('identifier');
        if (!$closure instanceof Closure) {
            throw new InvalidArgumentException('Throttle identifier option must be a Closure instance');
        }
        $this->_identifier = $closure($request);
    }

    /**
     * Initializes cache configuration.
     *
     * @return void
     */
    protected function _initCache(): void
    {
        if (Cache::getConfig(static::$cacheConfig) === null) {
            Cache::setConfig(static::$cacheConfig, [
                'className' => $this->_getDefaultCacheConfigClassName(),
                'prefix' => static::$cacheConfig . '_' . $this->_identifier,
                'duration' => $this->getConfig('interval'),
            ]);
        }
    }

    /**
     * Gets the className of the default CacheEngine so the Throttle cache
     * config can use the same. String cast is required to catch a DebugEngine
     * array/object for users with DebugKit enabled.
     *
     * @return string ClassName property of default Cache engine
     */
    protected function _getDefaultCacheConfigClassName(): string
    {
        $config = Cache::getConfig('default');
        $engine = (string)$config['className'];

        // short cache engine names can be returned immediately
        if (strpos($engine, '\\') === false) {
            return $engine;
        }
        // fully namespace cache engine names need extracting class name
        preg_match('/.+\\\\(.+)Engine/', $engine, $matches);

        return $matches[1];
    }

    /**
     * Atomically updates cache using default CakePHP increment offset 1.
     *
     * Please note that the cache key needs to be initialized to prevent
     * increment() failing on 0. A separate cache key is created to store
     * the interval expiration time in epoch.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request ServerRequestInterface instance
     * @return int
     */
    protected function _touch(ServerRequestInterface $request): int
    {
        if (Cache::read($this->_identifier, static::$cacheConfig) === null) {
            Cache::write($this->_identifier, 0, static::$cacheConfig);
            Cache::write(
                $this->_getCacheExpirationKey(),
                strtotime($this->getConfig('interval'), time()),
                static::$cacheConfig
            );
        }

        return Cache::increment($this->_identifier, $this->_getRequestWeight($request), static::$cacheConfig) ?: 0;
    }

    /**
     * Returns configured weight of each request, result of callable, or 1 when option
     * is misconfigured or the callable did not return integer >= 0
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request ServerRequestInterface instance
     * @return int
     */
    protected function _getRequestWeight(ServerRequestInterface $request): int
    {
        $configWeight = $this->getConfig('requestWeight');

        if (is_callable($configWeight)) {
            $configWeight = $configWeight($request);
        }

        if (!is_int($configWeight) || (is_int($configWeight) && $configWeight < 0)) {
            throw new InvalidArgumentException('Throttle requestWeight option, or number returned by callback, must be >= 0');
        }

        return $configWeight;
    }

    /**
     * Returns cache key holding the epoch cache expiration timestamp.
     *
     * @return string Cache key holding cache expiration in epoch.
     */
    protected function _getCacheExpirationKey(): string
    {
        return $this->_identifier . '_' . static::$cacheExpirationSuffix;
    }

    /**
     * Extends response with X-headers containing rate limiting information.
     *
     * @param \Psr\Http\Message\ResponseInterface $response ResponseInterface instance
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function _setHeaders(ResponseInterface $response): ResponseInterface
    {
        $headers = $this->getConfig('headers');

        if (!is_array($headers)) {
            return $response;
        }

        return $response
            ->withHeader($headers['limit'], (string)$this->getConfig('limit'))
            ->withHeader($headers['remaining'], (string)$this->_getRemainingConnections())
            ->withHeader(
                $headers['reset'],
                (string)Cache::read($this->_getCacheExpirationKey(), static::$cacheConfig)
            );
    }

    /**
     * Calculates the number of hits remaining before client reaches rate limit.
     *
     * @return int Number of remaining client hits, zero if limit is reached
     */
    protected function _getRemainingConnections(): int
    {
        $remaining = $this->getConfig('limit') - $this->_count;
        if ($remaining <= 0) {
            return 0;
        }

        return (int)$remaining;
    }
}
