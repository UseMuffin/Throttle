<?php
namespace Muffin\Throttle\Routing\Filter;

use Cake\Cache\Cache;
use Cake\Event\Event;
use Cake\Network\Request;
use Cake\Network\Response;
use Cake\Routing\DispatcherFilter;

class ThrottleFilter extends DispatcherFilter
{

    public static $cacheConfig = 'throttle';

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
            'interval' => '1 minute',
            'rate' => 10,
            'identifier' => function (Request $request) {
                return $request->clientIp();
            },
        ];

        parent::__construct($config);

        $this->config('when', [$this, 'when']);
        $this->_initCache();
    }

    /**
     * Class constructor.
     *
     * @param Cake\Network\Request $request Request instance
     * @return Cake\Network\Request
     */
    public function when(Request $request)
    {
        return $this->config('rate') > $this->_touch($request);
    }

    /**
     * beforeDispatch.
     *
     * @param Cake\Event\Event $event Event instance
     * @return Cake\Network\Response
     */
    public function beforeDispatch(Event $event)
    {
        $event->stopPropagation();
        $response = new Response(['body' => $this->config('message')]);
        $response->httpCodes([429 => 'Too Many Requests']);
        $response->statusCode(429);
        return $response;
    }

    /**
     * Initializes cache.
     *
     * @return void
     */
    protected function _initCache()
    {
        if (!Cache::config(static::$cacheConfig)) {
            Cache::config(static::$cacheConfig, [
                'prefix' => static::$cacheConfig . '_',
                'duration' => $this->config('interval'),
            ] + (array)Cache::config('default'));
        }
    }

    /**
     * Atomically updates cache using default CakePHP increment offset 1.
     *
     * @param Cake\Network\Request $request Request instance
     * @return Cake\Cache\Cache
     */
    protected function _touch(Request $request)
    {
        $key = $this->config('identifier');
        if (is_callable($key)) {
            $key = $key($request);
        }
        return Cache::increment($key, 1, static::$cacheConfig);
    }
}
