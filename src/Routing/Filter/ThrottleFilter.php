<?php
namespace Muffin\Throttle\Routing\Filter;

use Cake\Cache\Cache;
use Cake\Event\Event;
use Cake\Network\Request;
use Cake\Network\Response;
use Cake\Routing\DispatcherFilter;
use InvalidArgumentException;

class ThrottleFilter extends DispatcherFilter
{

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
     * Class constructor.
     *
     * @param array $config Configuration options
     */
    public function __construct($config = [])
    {
        $config += [
            'priority' => 1,
            'message' => 'Rate limit exceeded',
            'interval' => '+1 minute',
            'limit' => 10,
            'identifier' => function (Request $request) {
                return $request->clientIp();
            },
            'headers' => [
                'limit' => 'X-RateLimit-Limit',
                'remaining' => 'X-RateLimit-Remaining',
                'reset' => 'X-RateLimit-Reset'
            ]
        ];
        parent::__construct($config);
    }

    /**
     * beforeDispatch.
     *
     * @param Cake\Event\Event $event Event instance
     * @return mixed Cake\Network\Response when limit is reached, void otherwise
     */
    public function beforeDispatch(Event $event)
    {
        $this->_setIdentifier($event->data['request']);
        $this->_initCache();
        $this->_count = $this->_touch($event->data['request']);

        // client has not exceeded rate limit
        if ($this->_count <= $this->config('limit')) {
            $this->_setHeaders($event->data['response']);
            return;
        }

        // client has reached rate limit
        $event->stopPropagation();
        $response = new Response(['body' => $this->config('message')]);
        $response->httpCodes([429 => 'Too Many Requests']);
        $response->statusCode(429);
        return $response;
    }

    /**
     * afterDispatch.
     *
     * @param Cake\Event\Event $event Event instance
     * @return Cake\Network\Response Response instance
     */
    public function afterDispatch(Event $event)
    {
        $this->_setHeaders($event->data['response']);
        return $event->data['response'];
    }

    /**
     * Sets the identifier class property. Uses Throttle default IP address
     * based identifier unless a callable alternative is passed.
     *
     * @param Cake\Network\Request $request Request instance
     * @return void
     * @throws InvalidArgumentException
     */
    protected function _setIdentifier(Request $request)
    {
        $key = $this->config('identifier');
        if (!is_callable($this->config('identifier'))) {
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
        if (!Cache::config(static::$cacheConfig)) {
            Cache::config(static::$cacheConfig, [
                'className' => $this->_getDefaultCacheConfigClassName(),
                'prefix' => static::$cacheConfig . '_' . $this->_identifier,
                'duration' => $this->config('interval'),
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
        $config = Cache::config('default');
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
     * @return Cake\Cache\Cache
     */
    protected function _touch()
    {
        if (Cache::read($this->_identifier, static::$cacheConfig) === false) {
            Cache::write($this->_identifier, 0, static::$cacheConfig);
            Cache::write($this->_getCacheExpirationKey(), strtotime($this->config('interval'), time()), static::$cacheConfig);
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
     * @param Cake\Network\Response $response Response instance
     * @return void
     */
    protected function _setHeaders(Response $response)
    {
        if (!is_array($this->config('headers'))) {
            return;
        }
        $headers = $this->config('headers');

        $response->header([
            $headers['limit'] => $this->config('limit'),
            $headers['remaining'] => $this->_getRemainingConnections(),
            $headers['reset'] => Cache::read($this->_getCacheExpirationKey(), static::$cacheConfig)
        ]);
    }

    /**
     * Calculates the number of hits remaining before client reaches rate limit.
     *
     * @return int Number of remaining client hits, zero if limit is reached
     */
    protected function _getRemainingConnections()
    {
        $remaining = $this->config('limit') - $this->_count;
        if ($remaining <= 0) {
            return 0;
        }
        return $remaining;
    }
}
