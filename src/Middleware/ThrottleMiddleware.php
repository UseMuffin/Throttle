<?php
namespace Muffin\Throttle\Middleware;

use Cake\Cache\Cache;
use Cake\Core\InstanceConfigTrait;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Diactoros\Stream;

class ThrottleMiddleware
{

    use InstanceConfigTrait;

    /*
     * Default config for Throttle Middleware
     *
     * @var array
     */
    protected $_defaultConfig = [
        'message' => 'Rate limit exceeded',
        'interval' => '+1 minute',
        'limit' => 10,
        'headers' => [
            'limit' => 'X-RateLimit-Limit',
            'remaining' => 'X-RateLimit-Remaining',
            'reset' => 'X-RateLimit-Reset'
        ]
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
     * @var string
     */
    protected $_count;

    /**
     * ThrottleMiddleware constructor.
     *
     * @param array $config Configuration options
     */
    public function __construct($config = [])
    {
        $config += [
            'identifier' => function (ServerRequestInterface $request) {
                return $request->clientIp();
            }
        ];
        $this->setConfig($config);
    }

    /**
     * Called when the class is used as a function
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Request object
     * @param \Psr\Http\Message\ResponseInterface $response Response object
     * @param callable $next Next class in middelware
     * @return ResponseInterface
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, callable $next)
    {
        $this->_setIdentifier($request);
        $this->_initCache();
        $this->_count = $this->_touch();

        if ($this->_count > $this->getConfig('limit')) {
            $stream = new Stream('php://memory', 'wb+');
            $stream->write((string)$this->getConfig('message'));

            return $response->withStatus(429)
                ->withBody($stream);
        }

        $response = $next($request, $response);

        return $this->_setHeaders($response);
    }

    /**
     * Sets the identifier class property. Uses Throttle default IP address
     * based identifier unless a callable alternative is passed.
     *
     * @param \Psr\Http\Message\ResponseInterface $request RequestInterface instance
     * @return void
     * @throws \InvalidArgumentException
     */
    protected function _setIdentifier(RequestInterface $request)
    {
        $key = $this->getConfig('identifier');
        if (!is_callable($this->getConfig('identifier'))) {
            throw new \InvalidArgumentException('Throttle identifier option must be a callable');
        }
        $this->_identifier = $key($request);
    }

    /**
     * Initializes cache configuration.
     *
     * @return void
     */
    protected function _initCache()
    {
        if (!Cache::getConfig(static::$cacheConfig)) {
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
    protected function _getDefaultCacheConfigClassName()
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
     * @return \Cake\Cache\Cache
     */
    protected function _touch()
    {
        if (Cache::read($this->_identifier, static::$cacheConfig) === false) {
            Cache::write($this->_identifier, 0, static::$cacheConfig);
            Cache::write($this->_getCacheExpirationKey(), strtotime($this->getConfig('interval'), time()), static::$cacheConfig);
        }

        return Cache::increment($this->_identifier, 1, static::$cacheConfig);
    }

    /**
     * Returns cache key holding the epoch cache expiration timestamp.
     *
     * @return string Cache key holding cache expiration in epoch.
     */
    protected function _getCacheExpirationKey()
    {
        return $this->_identifier . '_' . static::$cacheExpirationSuffix;
    }

    /**
     * Extends response with X-headers containing rate limiting information.
     *
     * @param \Psr\Http\Message\ResponseInterface $response ResponseInterface instance
     * @return ResponseInterface
     */
    protected function _setHeaders(ResponseInterface $response)
    {
        if (!is_array($this->getConfig('headers'))) {
            return $response;
        }
        $headers = $this->getConfig('headers');

        return $response->withHeader($headers['limit'], (string)$this->getConfig('limit'))
            ->withHeader($headers['remaining'], (string)$this->_getRemainingConnections())
            ->withHeader($headers['reset'], (string)Cache::read($this->_getCacheExpirationKey(), static::$cacheConfig));
    }

    /**
     * Calculates the number of hits remaining before client reaches rate limit.
     *
     * @return int Number of remaining client hits, zero if limit is reached
     */
    protected function _getRemainingConnections()
    {
        $remaining = $this->getConfig('limit') - $this->_count;
        if ($remaining <= 0) {
            return 0;
        }

        return $remaining;
    }
}
