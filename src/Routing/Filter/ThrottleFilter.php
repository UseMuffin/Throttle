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

        // client has not exceeded rate limit
        if ($this->_count <= $this->getConfig('limit')) {
            $this->_setHeaders($event->getData('response'));

            return;
        }

        // client has reached rate limit
        $event->stopPropagation();
        $response = new Response([
            'body' => $this->getConfig('message'),
            'status' => 429,
        ]);

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
