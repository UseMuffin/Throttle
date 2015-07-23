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

        $this->assertEquals([$filter, 'when'], $result['when']);
        $this->assertEquals('Rate limit exceeded', $result['message']);
        $this->assertEquals('1 minute', $result['interval']);
        $this->assertEquals(10, $result['rate']);
        $this->assertTrue(is_callable($result['identifier']));
    }

    public function testBeforeDispatch()
    {
        $filter = new ThrottleFilter();

        $result = $filter->beforeDispatch(new Event('beforeDispatch'));
        $this->assertInstanceOf('Cake\Network\Response', $result);
        $this->assertEquals(429, $result->statusCode());
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
}
