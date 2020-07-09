<?php
declare(strict_types=1);

namespace Muffin\Throttle\ValueObject;

// phpcs:disable CakePHP.Commenting.FunctionComment
/**
 * @codeCoverageIgnore
 */
class ThrottleInfo
{
    /**
     * @var int
     */
    protected $limit;

    /**
     * @var int
     */
    protected $period;

    /**
     * @var string
     */
    protected $key;

    public function __construct(string $key = '', int $limit = 0, int $period = 0)
    {
        $this->key = $key;
        $this->limit = $limit;
        $this->period = $period;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * @return $this
     */
    public function setLimit(int $limit)
    {
        $this->limit = $limit;

        return $this;
    }

    public function getPeriod(): int
    {
        return $this->period;
    }

    /**
     * @return $this
     */
    public function setPeriod(int $period)
    {
        $this->period = $period;

        return $this;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @return $this
     */
    public function setKey(string $key)
    {
        $this->key = $key;

        return $this;
    }

    /**
     * @return $this
     */
    public function appendToKey(string $key)
    {
        $this->key .= '.' . $key;

        return $this;
    }
}
// phpcs:enable
