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
            'type' => 'text/plain',
            'headers' => [],
        ],
        'period' => 60,
        'limit' => 60,
        'headers' => [
            'limit' => 'X-RateLimit-Limit',
            'remaining' => 'X-RateLimit-Remaining',
            'reset' => 'X-RateLimit-Reset',
        ],
        'throttleCallback' => null,
    ];

    /**
     * Cache configuration name
     *
     * @var string
     */
    public static $cacheConfig = 'throttle';

    /**
     * Unique client identifier
     *
     * @var string
     */
    protected $_identifier;

    /**
     * Throttle Middleware constructor.
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
     * Process the request.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Psr\Http\Server\RequestHandlerInterface $handler The request handler.
     * @return \Psr\Http\Message\ResponseInterface A response.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->_setIdentifier($request);
        $this->_initCache();

        $throttleInfo = $this->_getThrottle($request);
        $rateLimit = $this->_rateLimit($throttleInfo['key'], $throttleInfo['limit'], $throttleInfo['period']);

        if ($rateLimit['exceeded']) {
            return $this->_returnErrorResponse($rateLimit);
        }

        $response = $handler->handle($request);

        return $this->_setHeaders($response, $rateLimit);
    }

    /**
     * Return error response when rate limit is exceeded.
     *
     * @param array $rateLimit Rate limiting info.
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function _returnErrorResponse(array $rateLimit): ResponseInterface
    {
        $config = $this->getConfig();

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

        $response = $response
            ->withStatus(429)
            ->withType($config['response']['type'])
            ->withStringBody($message);

        return $this->_setHeaders($response, $rateLimit);
    }

    /**
     * Get throttling data.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Server request instance.
     * @return array
     * @psalm-return {key: string, limit: int, period: int}
     */
    protected function _getThrottle(ServerRequestInterface $request): array
    {
        $throttle = [
            'key' => $this->_identifier,
            'limit' => $this->getConfig('limit'),
            'period' => $this->getConfig('period'),
        ];

        /** @param callable $callback */
        $callback = $this->getConfig('throttleCallback');
        if ($callback) {
            $throttle = $callback($request, $throttle);
        }

        return $throttle;
    }

    /**
     * Rate limit the request.
     *
     * @param string $key Cache key.
     * @param int $limit Limit.
     * @param int $period Period.
     * @return array
     * @psalm-return {limit: int, remaining: int, reset: int, exceeded: bool}
     */
    protected function _rateLimit(string $key, int $limit, int $period): array
    {
        $currentTime = time();
        $ttl = $period;
        $cacheEngine = Cache::pool(static::$cacheConfig);

        /** @psalm-var array{limit: int, remaining: int, reset: int}|null $rateLimit */
        $rateLimit = $cacheEngine->get($key);

        if ($rateLimit === null || $currentTime > $rateLimit['reset']) {
            $rateLimit = [
                'limit' => $limit,
                'remaining' => $limit,
                'reset' => $currentTime + $period,
            ];
        } elseif ($rateLimit['remaining'] < 1) {
            return $rateLimit + ['exceeded' => true];
        }

        $rateLimit['remaining'] = $rateLimit['remaining'] - 1;
        $ttl = $rateLimit['reset'] - $currentTime;

        $cacheEngine->set($key, $rateLimit, $ttl);

        return $rateLimit + ['exceeded' => false];
    }

    /**
     * Sets the identifier class property. By default used IP address
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
     * Extends response with X-headers containing rate limiting information.
     *
     * @param \Psr\Http\Message\ResponseInterface $response ResponseInterface instance
     * @param array $rateLimit Rate limiting info.
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function _setHeaders(ResponseInterface $response, array $rateLimit): ResponseInterface
    {
        $headers = $this->getConfig('headers');

        if (!is_array($headers)) {
            return $response;
        }

        return $response
            ->withHeader($headers['limit'], (string)$rateLimit['limit'])
            ->withHeader($headers['remaining'], (string)$rateLimit['remaining'])
            ->withHeader($headers['reset'], (string)$rateLimit['reset']);
    }
}
