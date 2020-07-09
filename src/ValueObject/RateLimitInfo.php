<?php
declare(strict_types=1);

namespace Muffin\Throttle\ValueObject;

// phpcs:disable CakePHP.Commenting.FunctionComment
/**
 * @codeCoverageIgnore
 */
class RateLimitInfo
{
    /**
     * @var int
     */
    protected $limit;

    /**
     * @var int
     */
    protected $calls;

    /**
     * @var int
     */
    protected $resetTimestamp;

    public function __construct(int $limit = 0, int $calls = 0, int $resetTimestamp = 0)
    {
        $this->limit = $limit;
        $this->calls = $calls;
        $this->resetTimestamp = $resetTimestamp;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * @param int $limit
     * @return $this
     */
    public function setLimit(int $limit)
    {
        $this->limit = $limit;

        return $this;
    }

    public function getCalls(): int
    {
        return $this->calls;
    }

    /**
     * @return $this
     */
    public function setCalls(int $calls)
    {
        $this->calls = $calls;

        return $this;
    }

    /**
     * @return $this
     */
    public function incrementCalls()
    {
        $this->calls++;

        return $this;
    }

    public function getResetTimestamp(): int
    {
        return $this->resetTimestamp;
    }

    /**
     * @return $this
     */
    public function setResetTimestamp(int $resetTimestamp)
    {
        $this->resetTimestamp = $resetTimestamp;

        return $this;
    }

    public function getRemaining(): int
    {
        $remaining = $this->limit - $this->calls;

        if ($remaining < 0) {
            $remaining = 0;
        }

        return $remaining;
    }

    public function limitExceeded(): bool
    {
        return $this->calls > $this->limit;
    }
}
// phpcs:enable
