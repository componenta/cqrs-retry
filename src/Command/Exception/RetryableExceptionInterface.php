<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Exception;

/**
 * Marker interface for exceptions that can be retried.
 *
 * Implement this interface on exceptions that represent
 * transient failures (deadlocks, connection timeouts, etc.).
 *
 * @example
 * ```php
 * final class DeadlockException extends RuntimeException implements RetryableExceptionInterface {}
 * ```
 */
interface RetryableExceptionInterface {}