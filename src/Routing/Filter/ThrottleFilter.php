<?php
namespace Muffin\Throttle\Routing\Filter;

use Cake\Cache\Cache;
use Cake\Event\Event;
use Cake\Network\Request;
use Cake\Network\Response;
use Cake\Routing\DispatcherFilter;
use InvalidArgumentException;
use Muffin\Throttle\ThrottleTrait;

class ThrottleFilter extends DispatcherFilter
{

    use ThrottleTrait;

    /**
     * Class constructor.
     *
     * @param array $config Configuration options
     */
    public function __construct($config = [])
    {
        $config += $this->_setConfiguration();
        parent::__construct($config);
    }

    /**
     * beforeDispatch.
     *
     * @param \Cake\Event\Event $event Event instance
     * @return mixed Cake\Network\Response when limit is reached, void otherwise
     */
    public function beforeDispatch(Event $event)
    {
        $this->_setIdentifier($event->data['request']);
        $this->_initCache();
        $this->_count = $this->_touch();

        $config = $this->config();

        // client has not exceeded rate limit
        if ($this->_count <= $config['limit']) {
            $this->_setHeaders($event->data['response']);

            return;
        }

        if (isset($config['message'])) {
            $message = $config['message'];
        } else {
            $message = $config['response']['body'];
        }

        // client has reached rate limit
        $event->stopPropagation();
        $response = new Response(['body' => $message]);
        $response->httpCodes([429 => 'Too Many Requests']);
        $response->statusCode(429);

        if (is_array($config['response']['headers'])) {
            foreach ($config['response']['headers'] as $key => $value) {
                $response->header($key, $value);
            }
        }

        return $response;
    }

    /**
     * afterDispatch.
     *
     * @param \Cake\Event\Event $event Event instance
     * @return \Cake\Network\Response Response instance
     */
    public function afterDispatch(Event $event)
    {
        $this->_setHeaders($event->data['response']);

        return $event->data['response'];
    }
}
