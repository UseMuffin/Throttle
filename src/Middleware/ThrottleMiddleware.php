<?php
declare(strict_types=1);

namespace Muffin\Throttle\Middleware;

use Cake\Cache\Cache;
use Cake\Core\InstanceConfigTrait;
use Cake\Http\Response;
use Closure;
use InvalidArgumentException;
use Muffin\Throttle\Dto\RateLimitInfo;
use Muffin\Throttle\Dto\ThrottleInfo;
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
     * @param \Muffin\Throttle\Dto\RateLimitInfo $rateLimit Rate limiting info.
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
     * @return \Muffin\Throttle\Dto\ThrottleInfo
     */
    protected function _getThrottle(ServerRequestInterface $request): ThrottleInfo
    {
        $throttle = new ThrottleInfo(
            $this->_identifier,
            $this->getConfig('limit'),
            $this->getConfig('period')
        );

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
     * @param \Muffin\Throttle\Dto\ThrottleInfo $throttle Throttling info.
     * @return \Muffin\Throttle\Dto\RateLimitInfo
     */
    protected function _rateLimit(ThrottleInfo $throttle): RateLimitInfo
    {
        $key = $throttle->getKey();
        $currentTime = time();
        $ttl = $throttle->getPeriod();
        $cacheEngine = Cache::pool(static::$cacheConfig);

        /** @var \Muffin\Throttle\Dto\RateLimitInfo|null $rateLimit */
        $rateLimit = $cacheEngine->get($key);

        if ($rateLimit === null || $currentTime > $rateLimit->getResetTimestamp()) {
            $rateLimit = new RateLimitInfo($throttle->getLimit(), 1, $currentTime + $throttle->getPeriod());
        } else {
            $rateLimit->incrementCalls();
        }

        if ($rateLimit->limitExceeded()) {
            $ttl = $rateLimit->getResetTimestamp() - $currentTime;
        }

        $cacheEngine->set($key, $rateLimit, $ttl);

        return $rateLimit;
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
     * @param \Muffin\Throttle\Dto\RateLimitInfo $rateLimit Rate limiting info.
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
