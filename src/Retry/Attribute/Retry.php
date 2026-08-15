<?php

declare(strict_types=1);

namespace Componenta\CQRS\Retry\Attribute;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Retry
{
    public int $delayMs;

    public function __construct(
        public int $attempts = 3,
        int $delayMs = 100,
        public float $multiplier = 1.0,
        public int $maxDelayMs = 10000,
    ) {
        if ($attempts < 1) {
            throw new InvalidArgumentException('Attempts must be at least 1');
        }

        if ($delayMs < 0) {
            throw new InvalidArgumentException('Delay must be non-negative');
        }

        if ($multiplier < 1.0 || !is_finite($multiplier)) {
            throw new InvalidArgumentException('Multiplier must be a finite number of at least 1.0');
        }

        if ($maxDelayMs < $delayMs) {
            throw new InvalidArgumentException('Max delay must be greater than or equal to delay');
        }

        if ($maxDelayMs > intdiv(PHP_INT_MAX, 1000)) {
            throw new InvalidArgumentException(
                'Max delay is too large to convert safely to microseconds.',
            );
        }

        $this->delayMs = $delayMs;
    }
}
