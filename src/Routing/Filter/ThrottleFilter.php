<?php
namespace Muffin\Throttle\Routing\Filter;

use Cake\Event\Event;
use Cake\Http\Response;
use Cake\Routing\DispatcherFilter;
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
     * @return mixed \Cake\Http\Response when limit is reached, void otherwise
     */
    public function beforeDispatch(Event $event)
    {
        $this->_setIdentifier($event->getData('request'));
        $this->_initCache();
        $this->_count = $this->_touch();

        $config = $this->getConfig();

        // client has not exceeded rate limit
        if ($this->_count <= $config['limit']) {
            $this->_setHeaders($event->getData('response'));

            return;
        }

        if (isset($config['message'])) {
            $message = $config['message'];
        } else {
            $message = $config['response']['body'];
        }

        // client has reached rate limit
        $event->stopPropagation();
        $response = new Response([
            'body' => $message,
            'status' => 429,
            'type' => $config['response']['type']
        ]);

        if (is_array($config['response']['headers'])) {
            foreach ($config['response']['headers'] as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }

    /**
     * afterDispatch.
     *
     * @param \Cake\Event\Event $event Event instance
     * @return \Cake\Http\Response Response instance
     */
    public function afterDispatch(Event $event)
    {
        $this->_setHeaders($event->getData('response'));

        return $event->getData('response');
    }
}
