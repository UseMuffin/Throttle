<?php
namespace Muffin\Throttle\Test\TestCase\Routing\Filter;

use Cake\Event\Event;
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

}
