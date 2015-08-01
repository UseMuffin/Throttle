<?php
namespace Muffin\Throttle\Test\TestCase\Routing\Filter;

use Cake\Cache\Cache;
use Cake\Event\Event;
use Cake\Network\Request;
use Cake\TestSuite\TestCase;
use Muffin\Throttle\Routing\Filter\ThrottleFilter;

class ThrottleFilterTest extends TestCase
{
    public function testConstructor()
    {
        $filter = new ThrottleFilter();
        $result = $filter->config();

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

        $object = new ThrottleFilter();
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod('_touch');
        $method->setAccessible(true);
        $method->invokeArgs($object, [new Request()]);
    }

    /**
     * Test if proper string is returned for use as cache expiration key.
     */
    public function testGetCacheExpirationKeyMethod()
    {
        $object = new ThrottleFilter();
        $reflection = new \ReflectionClass(get_class($object));
        $reflectionMethod = $reflection->getMethod('_getCacheExpirationKey');
        $reflectionMethod->setAccessible(true);
        $reflectionProperty = $reflection->getProperty('_identifier');
        $reflectionProperty->setAccessible(true);

        // test ip-adress based expiration key (Throttle default)
        $reflectionProperty->setValue($object, '10.33.10.10');
        $expected = '10.33.10.10_expires';
        $result = $reflectionMethod->invokeArgs($object, []);
        $this->assertEquals($expected, $result);

        // test JWT Bearer Token based expiration key
        $reflectionProperty->setValue($object, 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpZCI6IjJiMzdhMzVhLTAxNWEtNGUzMi04YTUyLTYzZjQ3ODBkNjY1NCIsImV4cCI6MTQzOTAzMjQ5OH0.U6PkSf6IfSc-o-14UiGy4Rbr9kqqETCKOclf92PXwHY');
        $expected = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpZCI6IjJiMzdhMzVhLTAxNWEtNGUzMi04YTUyLTYzZjQ3ODBkNjY1NCIsImV4cCI6MTQzOTAzMjQ5OH0.U6PkSf6IfSc-o-14UiGy4Rbr9kqqETCKOclf92PXwHY_expires';
        $result = $reflectionMethod->invokeArgs($object, []);
        $this->assertEquals($expected, $result);
    }
}
