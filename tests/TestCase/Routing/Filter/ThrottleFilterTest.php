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

    /**
     * Test when() method responsible for determining if the rate limit (10)
     * is exceeded. We mock the filter so we can make _count() return the
     * counters we need.
     */
    public function testWhen()
    {
        $mock = $this->getMockBuilder('\Muffin\Throttle\Routing\Filter\ThrottleFilter')
            ->setMethods(['_touch'])
            ->getMock();

        $mock->expects($this->at(0)) // test requests lower than rate limit
             ->method('_touch')
             ->will($this->returnValue(9));

         $mock->expects($this->at(1)) // test requests equal to rate limit
              ->method('_touch')
              ->will($this->returnValue(10));

          $mock->expects($this->at(2)) // test requests higher than rate limit
               ->method('_touch')
               ->will($this->returnValue(11));

        $request = new Request;
        $this->assertFalse($mock->when($request));
        $this->assertFalse($mock->when($request));
        $this->assertTrue($mock->when($request));
    }
}
