<?php
namespace Muffin\Throttle\Test\TestCase\Middleware;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use Muffin\Throttle\Middleware\ThrottleMiddleware;
use StdClass;

class ThrottleMiddlewareTest extends TestCase
{
    /**
     * setUp method
     *
     * @return void
     */
    public function setUp()
    {
        parent::setUp();

        $this->skipIf(version_compare(Configure::version(), '3.4') == -1 ? true : false);
        $this->skipIf(!function_exists('apcu_store'), 'APCu is not installed or configured properly.');
        if ((PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg')) {
            $this->skipIf(!ini_get('apc.enable_cli'), 'APC is not enabled for the CLI.');
        }
    }

    /**
     * Test __construct
     */
    public function testConstructor()
    {
        $middleware = new ThrottleMiddleware();
        $result = $middleware->getConfig();

        $this->assertEquals('Rate limit exceeded', $result['message']);
        $this->assertEquals('+1 minute', $result['interval']);
        $this->assertEquals(10, $result['limit']);
        $this->assertTrue(is_callable($result['identifier']));

        $expectedHeaders = [
            'limit' => 'X-RateLimit-Limit',
            'remaining' => 'X-RateLimit-Remaining',
            'reset' => 'X-RateLimit-Reset'
        ];
        $this->assertEquals($expectedHeaders, $result['headers']);
    }

    /**
     * Test __invoke
     */
    public function testInvoke()
    {
        Cache::drop('throttle');
        Cache::setConfig('throttle', [
            'className' => 'Cake\Cache\Engine\ApcEngine',
            'prefix' => 'throttle_'
        ]);

        $middleware = new ThrottleMiddleware([
            'limit' => 1
        ]);

        $response = new Response();
        $request = new ServerRequest([
            'environment' => [
                'REMOTE_ADDR' => '192.168.1.33'
            ]
        ]);

        $result = $middleware(
            $request,
            $response,
            function ($request, $response) {
                return $response;
            }
        );

        $expectedHeaders = [
            'X-RateLimit-Limit',
            'X-RateLimit-Remaining',
            'X-RateLimit-Reset'
        ];

        $this->assertInstanceOf('Cake\Http\Response', $result);
        $this->assertEquals(200, $result->getStatusCode());
        $this->assertEquals(3, count(array_intersect($expectedHeaders, array_keys($result->getHeaders()))));

        $result = $middleware(
            $request,
            $response,
            function ($request, $response) {
                return $response;
            }
        );

        $this->assertInstanceOf('Cake\Http\Response', $result);
        $this->assertEquals(429, $result->getStatusCode());
    }

    /**
     * Using the File Storage cache engine should throw a LogicException.
     *
     * @expectedException \LogicException
     */
    public function testFileCacheException()
    {
        Cache::setConfig('file', [
            'className' => 'Cake\Cache\Engine\FileEngine',
            'prefix' => 'throttle_'
        ]);

        $middleware = new ThrottleMiddleware();
        $reflection = $this->getReflection($middleware, '_touch');
        $reflection->method->invokeArgs($middleware, [new ServerRequest()]);
    }

    /**
     * Test setting the identifier class property
     *
     * @expectedException \LogicException
     */
    public function testSetIdentifierMethod()
    {
        $middleware = new ThrottleMiddleware();
        $reflection = $this->getReflection($middleware, '_setIdentifier');

        $request = new ServerRequest();
        $expected = $request->clientIp();
        $result = $reflection->method->invokeArgs($middleware, [new ServerRequest()]);
        $this->assertEquals($expected, $result);

        // should throw an exception if identifier is not a callable
        $middleware = new ThrottleMiddleware([
            'identifier' => 'non-callable-string'
        ]);
        $reflection = $this->getReflection($middleware, '_setIdentifier');
        $reflection->method->invokeArgs($middleware, [new ServerRequest()]);
    }

    /**
     * Test cache intialization.
     */
    public function testInitCacheMethod()
    {
        Cache::drop('default');
        Cache::setConfig('default', [
            'className' => 'Cake\Cache\Engine\FileEngine'
        ]);

        // test if new cache config is created if it does not exist
        Cache::drop('throttle');
        $middleware = new ThrottleMiddleware();
        $reflection = $this->getReflection($middleware, '_initCache');
        $reflection->method->invokeArgs($middleware, []);
        $expected = [
            'className' => 'File',
            'prefix' => 'throttle_',
            'duration' => '+1 minute'
        ];
        $this->assertEquals($expected, Cache::getConfig('throttle'));

        // cache config creation should be skipped if it already exists
        $this->assertNull($reflection->method->invokeArgs($middleware, []));
    }

    /**
     * Throttle uses the cache className as configured for the default
     * CacheEngine. Here we test if we can resolve the className.
     */
    public function testGetDefaultCacheConfigClassNameMethod()
    {
        $middleware = new ThrottleMiddleware();
        $reflection = $this->getReflection($middleware, '_getDefaultCacheConfigClassName');

        // Make sure short cache engine names get resolved properly
        Cache::drop('default');
        Cache::setConfig('default', [
            'className' => 'File'
        ]);

        $expected = 'File';
        $result = $reflection->method->invokeArgs($middleware, [new ServerRequest()]);
        $this->assertEquals($expected, $result);

        // Make sure fully namespaced cache engine names get resolved properly
        Cache::drop('default');
        Cache::setConfig('default', [
            'className' => 'Cake\Cache\Engine\FileEngine'
        ]);
        $expected = 'File';
        $result = $reflection->method->invokeArgs($middleware, [new ServerRequest()]);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test atomic updating client hits
     */
    public function testTouchMethod()
    {
        Cache::drop('throttle');
        Cache::setConfig('throttle', [
            'className' => 'Cake\Cache\Engine\ApcEngine',
            'prefix' => 'throttle_'
        ]);

        $middleware = new ThrottleMiddleware();
        $reflection = $this->getReflection($middleware, '_touch', '_identifier');
        $reflection->property->setValue($middleware, 'test-identifier');

        // initial hit should create cache count 1 + expiration key with epoch
        $reflection->method->invokeArgs($middleware, []);
        $this->assertEquals(1, Cache::read('test-identifier', 'throttle'));
        $this->assertNotFalse(Cache::read('test-identifier_expires', 'throttle'));
        $expires = Cache::read('test-identifier_expires', 'throttle');

        // second hit should increase counter but have identical expires key
        $reflection->method->invokeArgs($middleware, []);
        $this->assertEquals(2, Cache::read('test-identifier', 'throttle'));
        $this->assertEquals($expires, Cache::read('test-identifier_expires', 'throttle'));

        Cache::delete('test-identifier', 'throttle');
        Cache::drop('throttle');
    }

    /**
     * Test if proper string is returned for use as cache expiration key.
     */
    public function testGetCacheExpirationKeyMethod()
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
    public function testSetHeadersMethod()
    {
        // test disabled headers, should return null
        $middleware = new ThrottleMiddleware([
            'headers' => false
        ]);
        $reflection = $this->getReflection($middleware, '_setHeaders');
        $result = $reflection->method->invokeArgs($middleware, [new Response()]);
        $this->assertInstanceOf('Cake\Http\Response', $result);
    }

    /**
     * Test x-headers
     */
    public function testRemainingConnectionsMethod()
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

    /**
     * Convenience function to return an object with reflection class, accessible
     * protected method and optional accessible protected property.
     */
    protected function getReflection($object, $method = false, $property = false)
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
