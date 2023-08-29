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
    protected int $limit;

    /**
     * @var int
     */
    protected int $period;

    /**
     * @var string
     */
    protected string $key;

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
    public function setLimit(int $limit): self
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
    public function setPeriod(int $period): self
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
    public function setKey(string $key): self
    {
        $this->key = $key;

        return $this;
    }

    /**
     * @return $this
     */
    public function appendToKey(string $key): self
    {
        $this->key .= '.' . $key;

        return $this;
    }
}
// phpcs:enable
