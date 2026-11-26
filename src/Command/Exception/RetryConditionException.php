<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Exception;

use RuntimeException;
use Throwable;

/** A terminal condition failure preserving the handler failure being classified. */
final class RetryConditionException extends RuntimeException
{
    public function __construct(
        public readonly Throwable $primaryFailure,
        public readonly Throwable $conditionFailure,
        int $failedAttempt,
    ) {
        parent::__construct(
            sprintf('Retry condition failed after attempt %d; expected a boolean decision.', $failedAttempt),
            previous: $primaryFailure,
        );
    }
}
