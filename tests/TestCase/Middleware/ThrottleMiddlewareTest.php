<?php
declare(strict_types=1);

namespace Muffin\Throttle\Test\TestCase\Middleware;

use Cake\Cache\Cache;
use Cake\Cache\Engine\FileEngine;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use Muffin\Throttle\Middleware\ThrottleMiddleware;
use stdClass;
use TestApp\Http\TestRequestHandler;

class ThrottleMiddlewareTest extends TestCase
{
    protected $engineClass = FileEngine::class;

    /**
     * Test __construct
     */
    public function testConstructor(): void
    {
        $middleware = new ThrottleMiddleware();
        $result = $middleware->getConfig();

        $this->assertEquals('Rate limit exceeded', $result['response']['body']);
        $this->assertEquals([], $result['response']['headers']);
        $this->assertEquals(60, $result['period']);
        $this->assertEquals(60, $result['limit']);
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
        Cache::clear('throttle');

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
        $this->assertTrue(is_numeric($result->getHeaderLine('X-RateLimit-Reset')));
        $this->assertEquals(429, $result->getStatusCode());

        $result2 = $middleware->process(
            $request,
            new TestRequestHandler()
        );
        $this->assertEquals(429, $result2->getStatusCode());

        $this->assertSame(
            $result->getHeaderLine('X-RateLimit-Reset'),
            $result2->getHeaderLine('X-RateLimit-Reset')
        );
    }

    public function testProcessWithThrottleCallback(): void
    {
        Cache::drop('throttle');
        Cache::setConfig('throttle', [
            'className' => $this->engineClass,
            'prefix' => 'throttle_',
        ]);
        Cache::clear('throttle');

        $middleware = new ThrottleMiddleware([
            'limit' => 10,
            'response' => [
                'body' => 'Rate limit exceeded',
                'type' => 'json',
                'headers' => [
                    'Custom-Header' => 'test/test',
                ],
            ],
            'throttleCallback' => function ($request, $throttle) {
                if ($request->is('POST')) {
                    $throttle['key'] .= '.post';
                    $throttle['limit'] = 5;
                }

                return $throttle;
            },
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

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame('10', $result->getHeaderLine('X-RateLimit-Limit'));

        $request = new ServerRequest([
            'environment' => [
                'REMOTE_ADDR' => '192.168.1.33',
            ],
        ]);
        $request = $request->withMethod('POST');

        $result = $middleware->process(
            $request,
            new TestRequestHandler()
        );

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame('5', $result->getHeaderLine('X-RateLimit-Limit'));
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
