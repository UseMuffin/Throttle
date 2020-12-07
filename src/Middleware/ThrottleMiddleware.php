<?php
declare(strict_types=1);

namespace Muffin\Throttle\Middleware;

use Cake\Cache\Cache;
use Cake\Core\InstanceConfigTrait;
use Cake\Event\EventDispatcherInterface;
use Cake\Event\EventDispatcherTrait;
use Cake\Http\Response;
use Muffin\Throttle\ValueObject\RateLimitInfo;
use Muffin\Throttle\ValueObject\ThrottleInfo;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

class ThrottleMiddleware implements MiddlewareInterface, EventDispatcherInterface
{
    use InstanceConfigTrait;
    use EventDispatcherTrait;

    public const EVENT_BEFORE_THROTTLE = 'Throttle.beforeThrottle';

    public const EVENT_GET_IDENTIFER = 'Throttle.getIdentifier';

    public const EVENT_GET_THROTTLE_INFO = 'Throttle.getThrottleInfo';

    public const EVENT_BEFORE_CACHE_SET = 'Throtttle.beforeCacheSet';

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
        'cacheConfig' => 'throttle',
    ];

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
        $this->_defaultConfig['identifier'] = function ($request) {
            return $request->clientIp();
        };

        if (isset($config['interval'])) {
            $config['period'] = time() - strtotime($config['interval']);
            trigger_error(
                '`interval` config has been removed. Check the docs for replacement.',
                E_USER_WARNING
            );
        }

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
        $event = $this->dispatchEvent(self::EVENT_BEFORE_THROTTLE, [
            'request' => $request,
        ]);
        if ($event->isStopped()) {
            return $handler->handle($request);
        }

        $this->_setIdentifier($request);
        $this->_initCache();

        $throttle = $this->_getThrottle($request);
        $rateLimit = $this->_rateLimit($throttle);

        if ($rateLimit->limitExceeded()) {
            return $this->_getErrorResponse($rateLimit);
        }

        $response = $handler->handle($request);

        return $this->_setHeaders($response, $rateLimit);
    }

    /**
     * Return error response when rate limit is exceeded.
     *
     * @param \Muffin\Throttle\ValueObject\RateLimitInfo $rateLimit Rate limiting info.
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function _getErrorResponse(RateLimitInfo $rateLimit): ResponseInterface
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

        $retryAfter = $rateLimit->getResetTimestamp() - time();
        $response = $response
            ->withStatus(429)
            ->withHeader('Retry-After', (string)$retryAfter)
            ->withType($config['response']['type'])
            ->withStringBody($message);

        return $this->_setHeaders($response, $rateLimit);
    }

    /**
     * Get throttling data.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Server request instance.
     * @return \Muffin\Throttle\ValueObject\ThrottleInfo
     */
    protected function _getThrottle(ServerRequestInterface $request): ThrottleInfo
    {
        $throttle = new ThrottleInfo(
            $this->_identifier,
            $this->getConfig('limit'),
            $this->getConfig('period')
        );

        $event = $this->dispatchEvent(self::EVENT_GET_THROTTLE_INFO, [
            'request' => $request,
            'throttle' => $throttle,
        ]);

        return $event->getResult() ?? $event->getData()['throttle'];
    }

    /**
     * Rate limit the request.
     *
     * @param \Muffin\Throttle\ValueObject\ThrottleInfo $throttle Throttling info.
     * @return \Muffin\Throttle\ValueObject\RateLimitInfo
     */
    protected function _rateLimit(ThrottleInfo $throttle): RateLimitInfo
    {
        $key = $throttle->getKey();
        $currentTime = time();
        $ttl = $throttle->getPeriod();
        $cacheEngine = Cache::pool($this->getConfig('cacheConfig'));

        /** @var \Muffin\Throttle\ValueObject\RateLimitInfo|null $rateLimit */
        $rateLimit = $cacheEngine->get($key);

        if ($rateLimit === null || $currentTime > $rateLimit->getResetTimestamp()) {
            $rateLimit = new RateLimitInfo($throttle->getLimit(), 1, $currentTime + $throttle->getPeriod());
        } else {
            $rateLimit->incrementCalls();
        }

        if ($rateLimit->limitExceeded()) {
            $ttl = $rateLimit->getResetTimestamp() - $currentTime;
        }

        $event = $this->dispatchEvent(self::EVENT_BEFORE_CACHE_SET, [
            'rateLimit' => $rateLimit,
            'ttl' => $ttl,
            'throttleInfo' => clone $throttle,
        ]);

        $cacheEngine->set($key, $event->getData()['rateLimit'], $event->getData()['ttl']);

        return $rateLimit;
    }

    /**
     * Sets the identifier class property. By default used IP address
     * based identifier unless a callable alternative is passed.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request RequestInterface instance
     * @return string
     * @throws \InvalidArgumentException
     */
    protected function _setIdentifier(ServerRequestInterface $request): string
    {
        $event = $this->dispatchEvent(self::EVENT_GET_IDENTIFER, [
            'request' => $request,
        ]);
        $identifier = $event->getResult() ?: $this->getConfig('identifier')($request);

        if (!is_string($identifier)) {
            throw new RuntimeException('Throttle identifier must be a string.');
        }

        return $this->_identifier = $identifier;
    }

    /**
     * Initializes cache configuration.
     *
     * @return void
     */
    protected function _initCache(): void
    {
        $cacheConfig = $this->getConfig('cacheConfig');

        if (Cache::getConfig($cacheConfig) === null) {
            Cache::setConfig($cacheConfig, [
                'className' => $this->_getDefaultCacheConfigClassName(),
                'prefix' => $cacheConfig . '_' . $this->_identifier,
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
     * @param \Muffin\Throttle\ValueObject\RateLimitInfo $rateLimit Rate limiting info.
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function _setHeaders(ResponseInterface $response, RateLimitInfo $rateLimit): ResponseInterface
    {
        $headers = $this->getConfig('headers');

        if (!is_array($headers)) {
            return $response;
        }

        return $response
            ->withHeader($headers['limit'], (string)$rateLimit->getLimit())
            ->withHeader($headers['remaining'], (string)$rateLimit->getRemaining())
            ->withHeader($headers['reset'], (string)$rateLimit->getResetTimestamp());
    }
}
