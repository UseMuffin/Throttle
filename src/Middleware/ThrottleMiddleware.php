<?php
namespace Muffin\Throttle\Middleware;

use Cake\Core\InstanceConfigTrait;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Muffin\Throttle\ThrottleTrait;
use Zend\Diactoros\Stream;

class ThrottleMiddleware
{

    use InstanceConfigTrait;
    use ThrottleTrait;

    /**
     * Default Configuration array
     *
     * @var array
     */
    protected $_defaultConfig = [];

    /**
     * ThrottleMiddleware constructor.
     *
     * @param array $config Configuration options
     */
    public function __construct($config = [])
    {
        $config += $this->_setConfiguration();
        $this->setConfig($config);
    }

    /**
     * Called when the class is used as a function
     *
     * @param \Cake\Http\ServerRequest $request Request object
     * @param \Cake\Http\Reponse $response Response object
     * @param callable $next Next class in middleware
     * @return \Cake\Http\Reponse
     */
    public function __invoke(ServerRequest $request, Response $response, callable $next)
    {
        $this->_setIdentifier($request);
        $this->_initCache();
        $this->_count = $this->_touch();

        $config = $this->getConfig();

        if ($this->_count > $config['limit']) {
            $stream = new Stream('php://memory', 'wb+');

            if (is_array($config['response']['headers'])) {
                foreach ($config['response']['headers'] as $name => $value) {
                    $response = $response->withHeader($name, $value);
                }
            }

            if (isset($config['message'])) {
                $stream->write((string)$config['message']);
            } else {
                $stream->write((string)$config['response']['body']);
            }

            return $response->withStatus(429)
                ->withType($config['response']['type'])
                ->withBody($stream);
        }

        $response = $next($request, $response);

        return $this->_setHeaders($response);
    }
}
