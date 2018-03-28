<?php
namespace Muffin\Throttle\Test\TestCase\Routing\Filter;

use Cake\Cache\Cache;
use Cake\Event\Event;
use Cake\Network\Request;
use Cake\Network\Response;
use Cake\TestSuite\TestCase;
use Muffin\Throttle\Routing\Filter\ThrottleFilter;
use StdClass;

class ThrottleFilterTest extends TestCase
{
    /**
     * setUp method
     *
     * @return void
     */
    public function setUp()
    {
        parent::setUp();

        $this->skipIf(!function_exists('apcu_store'), 'APCu is not installed or configured properly.');
        if ((PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg')) {
            $this->skipIf(!ini_get('apc.enable_cli'), 'APC is not enabled for the CLI.');
        }
    }

    public function testConstructor()
    {
        $filter = new ThrottleFilter();
        $result = $filter->config();

        $this->assertEquals('Rate limit exceeded', $result['response']['body']);
        $this->assertEquals([], $result['response']['headers']);
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
     * Test beforeDispatch
     */
    public function testBeforeDispatch()
    {
        Cache::drop('throttle');
        Cache::config('throttle', [
            'className' => 'Cake\Cache\Engine\ApcEngine',
            'prefix' => 'throttle_'
        ]);

        $filter = new ThrottleFilter([
            'limit' => 1,
            'response' => [
                'body' => 'Rate limit exceeded',
                'type' => 'json',
                'headers' => [
                    'Custom-Header' => 'test/test'
                ]
            ]
        ]);
        $response = new Response();
        $request = new Request([
            'environment' => [
                'REMOTE_ADDR' => '192.168.1.2'
            ]
        ]);

        $event = new Event('Dispatcher.beforeDispatch', $this, compact('request', 'response'));
        $this->assertNull($filter->beforeDispatch($event));
        $this->assertFalse($event->isStopped());

        $result = $filter->beforeDispatch($event);
        $this->assertInstanceOf('Cake\Network\Response', $result);
        $this->assertEquals(429, $result->statusCode());
        $this->assertEquals('application/json', $result->type());
        $this->assertTrue($event->isStopped());

        $expectedHeaders = [
            'Custom-Header',
            'Content-Type'
        ];
        $this->assertEquals($expectedHeaders, array_keys($result->header()));
    }

    /**
     * Test afterDispatch
     */
    public function testAfterDispatch()
    {
        Cache::drop('throttle');
        Cache::config('throttle', [
            'className' => 'Cake\Cache\Engine\ApcEngine',
            'prefix' => 'throttle_'
        ]);

        $filter = new ThrottleFilter([
            'limit' => 1
        ]);
        $response = new Response();
        $request = new Request([
            'environment' => [
                'HTTP_CLIENT_IP' => '192.168.1.2'
            ]
        ]);

        $event = new Event('Dispatcher.beforeDispatch', $this, compact('request', 'response'));
        $result = $filter->afterDispatch($event);
        $this->assertInstanceOf('Cake\Network\Response', $result);
    }
    /**
     * Using the File Storage cache engine should throw a LogicException.
     *
     * @expectedException \LogicException
     */
    public function testFileCacheException()
    {
        Cache::config('file', [
            'className' => 'Cake\Cache\Engine\FileEngine',
            'prefix' => 'throttle_'
        ]);

        $filter = new ThrottleFilter();
        $reflection = $this->getReflection($filter, '_touch');
        $reflection->method->invokeArgs($filter, [new Request()]);
    }

    /**
     * Test setting the identifier class property
     *
     * @expectedException \LogicException
     */
    public function testSetIdentifierMethod()
    {
        $filter = new ThrottleFilter();
        $reflection = $this->getReflection($filter, '_setIdentifier');

        $request = new Request();
        $expected = $request->clientIp();
        $result = $reflection->method->invokeArgs($filter, [new Request()]);
        $this->assertEquals($expected, $result);

        // should throw an exception if identifier is not a callable
        $filter = new ThrottleFilter([
            'identifier' => 'non-callable-string'
        ]);
        $reflection = $this->getReflection($filter, '_setIdentifier');
        $reflection->method->invokeArgs($filter, [new Request()]);
    }

    /**
     * Test cache intialization.
     */
    public function testInitCacheMethod()
    {
        Cache::drop('default');
        Cache::config('default', [
             'className' => 'Cake\Cache\Engine\FileEngine'
        ]);

        // test if new cache config is created if it does not exist
        Cache::drop('throttle');
        $filter = new ThrottleFilter();
        $reflection = $this->getReflection($filter, '_initCache');
        $reflection->method->invokeArgs($filter, []);
        $expected = [
            'className' => 'File',
            'prefix' => 'throttle_',
            'duration' => '+1 minute'
        ];
        $this->assertEquals($expected, Cache::config('throttle'));

        // cache config creation should be skipped if it already exists
        $this->assertNull($reflection->method->invokeArgs($filter, []));
    }

    /**
     * Throttle uses the cache className as configured for the default
     * CacheEngine. Here we test if we can resolve the className.
     */
    public function testGetDefaultCacheConfigClassNameMethod()
    {
        $filter = new ThrottleFilter();
        $reflection = $this->getReflection($filter, '_getDefaultCacheConfigClassName');

        // Make sure short cache engine names get resolved properly
        Cache::drop('default');
        Cache::config('default', [
            'className' => 'File'
        ]);
        $expected = 'File';
        $result = $reflection->method->invokeArgs($filter, [new Request()]);
        $this->assertEquals($expected, $result);

        // Make sure fully namespaced cache engine names get resolved properly
        Cache::drop('default');
        Cache::config('default', [
            'className' => 'Cake\Cache\Engine\FileEngine'
        ]);
        $expected = 'File';
        $result = $reflection->method->invokeArgs($filter, [new Request()]);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test atomic updating client hits
     */
    public function testTouchMethod()
    {
        Cache::drop('throttle');
        Cache::config('throttle', [
            'className' => 'Cake\Cache\Engine\ApcEngine',
            'prefix' => 'throttle_'
        ]);

        $filter = new ThrottleFilter();
        $reflection = $this->getReflection($filter, '_touch', '_identifier');
        $reflection->property->setValue($filter, 'test-identifier');

        // initial hit should create cache count 1 + expiration key with epoch
        $reflection->method->invokeArgs($filter, []);
        $this->assertEquals(1, Cache::read('test-identifier', 'throttle'));
        $this->assertNotFalse(Cache::read('test-identifier_expires', 'throttle'));
        $expires = Cache::read('test-identifier_expires', 'throttle');

        // second hit should increase counter but have identical expires key
        $reflection->method->invokeArgs($filter, []);
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
        $filter = new ThrottleFilter();
        $reflection = $this->getReflection($filter, '_getCacheExpirationKey', '_identifier');

        // test ip-adress based expiration key (Throttle default)
        $reflection->property->setValue($filter, '10.33.10.10');
        $expected = '10.33.10.10_expires';
        $result = $reflection->method->invokeArgs($filter, []);
        $this->assertEquals($expected, $result);

        // test long JWT Bearer Token based expiration key
        $reflection->property->setValue($filter, 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpZCI6IjJiMzdhMzVhLTAxNWEtNGUzMi04YTUyLTYzZjQ3ODBkNjY1NCIsImV4cCI6MTQzOTAzMjQ5OH0.U6PkSf6IfSc-o-14UiGy4Rbr9kqqETCKOclf92PXwHY');
        $expected = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpZCI6IjJiMzdhMzVhLTAxNWEtNGUzMi04YTUyLTYzZjQ3ODBkNjY1NCIsImV4cCI6MTQzOTAzMjQ5OH0.U6PkSf6IfSc-o-14UiGy4Rbr9kqqETCKOclf92PXwHY_expires';
        $result = $reflection->method->invokeArgs($filter, []);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test x-headers
     */
    public function testSetHeadersMethod()
    {
        // test disabled headers, should return null
        $filter = new ThrottleFilter([
            'headers' => false
        ]);
        $reflection = $this->getReflection($filter, '_setHeaders');
        $result = $reflection->method->invokeArgs($filter, [new Response()]);
        $this->assertInstanceOf('Cake\Network\Response', $result);
    }

    /**
     * Test x-headers
     */
    public function testRemainingConnectionsMethod()
    {
        $filter = new ThrottleFilter();
        $reflection = $this->getReflection($filter, '_getRemainingConnections', '_count');

        $reflection->property->setValue($filter, 7);
        $result = $reflection->method->invokeArgs($filter, []);
        $this->assertEquals('3', $result);

        $reflection->property->setValue($filter, 0);
        $result = $reflection->method->invokeArgs($filter, []);
        $this->assertEquals('10', $result);

        $reflection->property->setValue($filter, 10);
        $result = $reflection->method->invokeArgs($filter, []);
        $this->assertEquals('0', $result);

        $reflection->property->setValue($filter, 11);
        $result = $reflection->method->invokeArgs($filter, []);
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
