<?php
declare(strict_types=1);

namespace Muffin\Throttle\Test\TestCase\Middleware;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ApcuEngine;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;
use Muffin\Throttle\Middleware\ThrottleMiddleware;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;
use TestApp\Http\TestRequestHandler;

class ThrottleMiddlewareTest extends TestCase
{
    protected $engineClass;

    /**
     * setUp method
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->skipIf(!function_exists('apcu_store'), 'APCu is not installed or configured properly.');
        if ((PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg')) {
            $this->skipIf(!ini_get('apc.enable_cli'), 'APC is not enabled for the CLI.');
        }

        $this->engineClass = ApcuEngine::class;
    }

    /**
     * Test __construct
     */
    public function testConstructor(): void
    {
        $middleware = new ThrottleMiddleware();
        $result = $middleware->getConfig();

        $this->assertEquals('Rate limit exceeded', $result['response']['body']);
        $this->assertEquals([], $result['response']['headers']);
        $this->assertEquals('+1 minute', $result['interval']);
        $this->assertEquals(10, $result['limit']);
        $this->assertTrue(is_callable($result['identifier']));

        $expectedHeaders = [
            'limit' => 'X-RateLimit-Limit',
            'remaining' => 'X-RateLimit-Remaining',
            'reset' => 'X-RateLimit-Reset',
        ];
        $this->assertEquals($expectedHeaders, $result['headers']);
    }

    /**
     * Test __construct with partial config provided
     */
    public function testConstructorWithPartialConfigProvided(): void
    {
        $middleware = new ThrottleMiddleware([
            'response' => [
                'body' => 'Rate limit exceeded',
            ],
        ]);
        $result = $middleware->getConfig();

        $this->assertTrue(array_key_exists('headers', $result['response']));
    }

    /**
     * Test process
     */
    public function testProcess(): void
    {
        Cache::drop('throttle');
        Cache::setConfig('throttle', [
            'className' => $this->engineClass,
            'prefix' => 'throttle_',
        ]);

        $middleware = new ThrottleMiddleware([
            'limit' => 1,
            'response' => [
                'body' => 'Rate limit exceeded',
                'type' => 'json',
                'headers' => [
                    'Custom-Header' => 'test/test',
                ],
            ],
        ]);

        $request = new ServerRequest([
            'environment' => [
                'REMOTE_ADDR' => '192.168.1.33',
            ],
        ]);

        $result = $middleware->process(
            $request,
            new TestRequestHandler()
        );

        $expectedHeaders = [
            'X-RateLimit-Limit',
            'X-RateLimit-Remaining',
            'X-RateLimit-Reset',
        ];

        $this->assertInstanceOf(Response::class, $result);
        $this->assertEquals(200, $result->getStatusCode());
        $this->assertEquals(3, count(array_intersect($expectedHeaders, array_keys($result->getHeaders()))));

        $result = $middleware->process(
            $request,
            new TestRequestHandler()
        );

        $expectedHeaders = [
            'Custom-Header',
            'Content-Type',
        ];

        $this->assertInstanceOf(Response::class, $result);
        $this->assertEquals('application/json', $result->getType());
        $this->assertEquals(2, count(array_intersect($expectedHeaders, array_keys($result->getHeaders()))));
        $this->assertEquals(429, $result->getStatusCode());
    }

    /**
     * Using the File Storage cache engine should throw a LogicException.
     */
    public function testFileCacheException(): void
    {
        $this->expectException(\TypeError::class);

        Cache::setConfig('file', [
            'className' => 'Cake\Cache\Engine\FileEngine',
            'prefix' => 'throttle_',
        ]);

        $middleware = new ThrottleMiddleware();
        $reflection = $this->getReflection($middleware, '_touch');
        $reflection->method->invokeArgs($middleware, [new ServerRequest()]);
    }

    /**
     * Test setting the identifier class property
     */
    public function testSetIdentifierMethod(): void
    {
        $this->expectException(\LogicException::class);

        $middleware = new ThrottleMiddleware();
        $reflection = $this->getReflection($middleware, '_setIdentifier');

        $request = new ServerRequest();
        $expected = $request->clientIp();
        $result = $reflection->method->invokeArgs($middleware, [new ServerRequest()]);
        $this->assertEquals($expected, $result);

        // should throw an exception if identifier is not a callable
        $middleware = new ThrottleMiddleware([
            'identifier' => 'non-callable-string',
        ]);
        $reflection = $this->getReflection($middleware, '_setIdentifier');
        $reflection->method->invokeArgs($middleware, [new ServerRequest()]);
    }

    /**
     * Test cache intialization.
     */
    public function testInitCacheMethod(): void
    {
        Cache::drop('default');
        Cache::setConfig('default', [
            'className' => 'Cake\Cache\Engine\FileEngine',
        ]);

        // test if new cache config is created if it does not exist
        Cache::drop('throttle');

        $middleware = new ThrottleMiddleware();
        $reflection = $this->getReflection($middleware, '_initCache');
        $reflection->method->invokeArgs($middleware, []);

        $expected = [
            'className' => 'File',
            'prefix' => 'throttle_',
            'duration' => '+1 minute',
        ];

        $this->assertEquals($expected, Cache::getConfig('throttle'));

        // cache config creation should be skipped if it already exists
        $this->assertNull($reflection->method->invokeArgs($middleware, []));
    }

    /**
     * Throttle uses the cache className as configured for the default
     * CacheEngine. Here we test if we can resolve the className.
     */
    public function testGetDefaultCacheConfigClassNameMethod(): void
    {
        $middleware = new ThrottleMiddleware();
        $reflection = $this->getReflection($middleware, '_getDefaultCacheConfigClassName');

        // Make sure short cache engine names get resolved properly
        Cache::drop('default');
        Cache::setConfig('default', [
            'className' => 'File',
        ]);

        $expected = 'File';
        $result = $reflection->method->invokeArgs($middleware, [new ServerRequest()]);
        $this->assertEquals($expected, $result);

        // Make sure fully namespaced cache engine names get resolved properly
        Cache::drop('default');
        Cache::setConfig('default', [
            'className' => 'Cake\Cache\Engine\FileEngine',
        ]);
        $expected = 'File';
        $result = $reflection->method->invokeArgs($middleware, [new ServerRequest()]);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test atomic updating client hits
     */
    public function testTouchMethod(): void
    {
        Cache::drop('throttle');
        Cache::setConfig('throttle', [
            'className' => $this->engineClass,
            'prefix' => 'throttle_',
        ]);

        $middleware = new ThrottleMiddleware();
        $reflection = $this->getReflection($middleware, '_touch', '_identifier');
        $reflection->property->setValue($middleware, 'test-identifier');

        // initial hit should create cache count 1 + expiration key with epoch
        $reflection->method->invokeArgs($middleware, [new ServerRequest()]);
        $this->assertEquals(1, Cache::read('test-identifier', 'throttle'));
        $this->assertTrue((bool)Cache::read('test-identifier_expires', 'throttle'));
        $expires = Cache::read('test-identifier_expires', 'throttle');

        // second hit should increase counter but have identical expires key
        $reflection->method->invokeArgs($middleware, [new ServerRequest()]);
        $this->assertEquals(2, Cache::read('test-identifier', 'throttle'));
        $this->assertEquals($expires, Cache::read('test-identifier_expires', 'throttle'));

        Cache::delete('test-identifier', 'throttle');
        Cache::drop('throttle');
    }

    /**
     * Test if proper string is returned for use as cache expiration key.
     */
    public function testGetCacheExpirationKeyMethod(): void
    {
        $middelware = new ThrottleMiddleware();
        $reflection = $this->getReflection($middelware, '_getCacheExpirationKey', '_identifier');

        // test ip-adress based expiration key (Throttle default)
        $reflection->property->setValue($middelware, '10.33.10.10');
        $expected = '10.33.10.10_expires';
        $result = $reflection->method->invokeArgs($middelware, []);
        $this->assertEquals($expected, $result);

        // test long JWT Bearer Token based expiration key
        $reflection->property->setValue($middelware, 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpZCI6IjJiMzdhMzVhLTAxNWEtNGUzMi04YTUyLTYzZjQ3ODBkNjY1NCIsImV4cCI6MTQzOTAzMjQ5OH0.U6PkSf6IfSc-o-14UiGy4Rbr9kqqETCKOclf92PXwHY');
        $expected = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpZCI6IjJiMzdhMzVhLTAxNWEtNGUzMi04YTUyLTYzZjQ3ODBkNjY1NCIsImV4cCI6MTQzOTAzMjQ5OH0.U6PkSf6IfSc-o-14UiGy4Rbr9kqqETCKOclf92PXwHY_expires';
        $result = $reflection->method->invokeArgs($middelware, []);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test x-headers
     */
    public function testSetHeadersMethod(): void
    {
        // test disabled headers, should return null
        $middleware = new ThrottleMiddleware([
            'headers' => false,
        ]);
        $reflection = $this->getReflection($middleware, '_setHeaders');
        $result = $reflection->method->invokeArgs($middleware, [new Response()]);
        $this->assertInstanceOf(Response::class, $result);
    }

    /**
     * Test x-headers
     */
    public function testRemainingConnectionsMethod(): void
    {
        $middleware = new ThrottleMiddleware();
        $reflection = $this->getReflection($middleware, '_getRemainingConnections', '_count');

        $reflection->property->setValue($middleware, 7);
        $result = $reflection->method->invokeArgs($middleware, []);
        $this->assertEquals('3', $result);

        $reflection->property->setValue($middleware, 0);
        $result = $reflection->method->invokeArgs($middleware, []);
        $this->assertEquals('10', $result);

        $reflection->property->setValue($middleware, 10);
        $result = $reflection->method->invokeArgs($middleware, []);
        $this->assertEquals('0', $result);

        $reflection->property->setValue($middleware, 11);
        $result = $reflection->method->invokeArgs($middleware, []);
        $this->assertEquals('0', $result);
    }

    public function testConfigurableRequestWeight(): void
    {
        $middleware = new ThrottleMiddleware([
            'requestWeight' => function (ServerRequestInterface $request) {
                if (!($request instanceof ServerRequest)) {
                    return 1;
                }

                return 5;
            },
        ]);
        $reflection = $this->getReflection($middleware, '_getRequestWeight');

        $this->assertEquals(5, $reflection->method->invokeArgs($middleware, [new ServerRequest()]));

        $invalidFunctions = [
            function () {
            },
            function ($param1) {
            },
            function ($param) {

                return null;
            },
            function ($param) {

                return -1;
            },
            function ($param) {

                return 'string';
            },
            function ($param) {

                return $param;
            },
            function ($param) {

                return $param->clientIp();
            },
            null,
        ];

        foreach ($invalidFunctions as $invalidFunction) {
            $middleware = new ThrottleMiddleware([
                'requestWeight' => $invalidFunction,
            ]);
            $reflection = $this->getReflection($middleware, '_getRequestWeight');
            $this->expectException(InvalidArgumentException::class);
            $reflection->method->invokeArgs($middleware, [new ServerRequest()]);
        }

        $invalidConfigurations = [
            'string',
            false,
            -1,
        ];

        foreach ($invalidConfigurations as $invalidConfiguration) {
            $middleware = new ThrottleMiddleware([
                'requestWeight' => $invalidConfiguration,
            ]);
            $reflection = $this->getReflection($middleware, '_getRequestWeight');
            $this->expectException(InvalidArgumentException::class);
            $reflection->method->invokeArgs($middleware, [new ServerRequest()]);
        }
    }

    /**
     * Convenience function to return an object with reflection class, accessible
     * protected method and optional accessible protected property.
     */
    protected function getReflection(object $object, ?string $method = null, ?string $property = null): object
    {
        $obj = new stdClass();
        $obj->class = new \ReflectionClass(get_class($object));

        $obj->method = null;
        if ($method) {
            $obj->method = $obj->class->getMethod($method);
            $obj->method->setAccessible(true);
        }

        if ($property) {
            $obj->property = $obj->class->getProperty($property);
            $obj->property->setAccessible(true);
        }

        return $obj;
    }
}
