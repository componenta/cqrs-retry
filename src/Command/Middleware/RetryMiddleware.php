<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Middleware;

use Componenta\CQRS\Command\Exception\RetryableExceptionInterface;
use Componenta\CQRS\Command\Metadata\CommandMetadataProviderInterface;
use Componenta\CQRS\Command\Metadata\ReflectionCommandMetadataProvider;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Retry\Attribute\Retry;
use Throwable;

/**
 * Retries command execution on transient failures.
 *
 * Retries exceptions that either:
 * - Implement RetryableExceptionInterface
 * - Are listed in constructor's $retryableExceptions
 *
 * Commands must be marked with #[Retry] attribute to enable retries.
 * Supports fixed delay and exponential backoff with optional jitter.
 *
 * @example
 * ```php
 * #[Retry(attempts: 3, delayMs: 100, multiplier: 2.0)]
 * final readonly class ProcessPaymentCommand {}
 *
 * // Will retry up to 3 times with delays: ~100ms, ~200ms, ~400ms (with jitter)
 * ```
 */
final readonly class RetryMiddleware implements MiddlewareInterface
{
    private CommandMetadataProviderInterface $metadata;

    /**
     * @param list<class-string<Throwable>> $retryableExceptions
     *        Additional exception classes to retry (besides RetryableExceptionInterface)
     * @param bool $jitter Add random jitter to delay (±25%) to prevent thundering herd
     */
    public function __construct(
        private array $retryableExceptions = [],
        private bool $jitter = true,
        ?CommandMetadataProviderInterface $metadata = null,
    ) {
        $this->metadata = $metadata ?? new ReflectionCommandMetadataProvider();
    }

    public function execute(OperationInterface $operation, OperationHandlerInterface $handler): OperationInterface
    {
        $retry = $this->metadata->get($operation->command, Retry::class);

        if ($retry === null) {
            return $handler->handle($operation);
        }

        return $this->executeWithRetry($operation, $handler, $retry);
    }

    private function executeWithRetry(
        OperationInterface $operation,
        OperationHandlerInterface $handler,
        Retry $retry,
    ): OperationInterface {
        $attempt = 0;
        $delayMs = $retry->delayMs;

        while (true) {
            $attempt++;

            try {
                return $handler->handle($operation);
            } catch (Throwable $e) {
                if ($attempt >= $retry->attempts || !$this->isRetryable($e)) {
                    throw $e;
                }

                $this->sleep($this->applyJitter($delayMs));

                $delayMs = min(
                    (int) ($delayMs * $retry->multiplier),
                    $retry->maxDelayMs,
                );
            }
        }
    }

    private function isRetryable(Throwable $e): bool
    {
        if ($e instanceof RetryableExceptionInterface) {
            return true;
        }

        return array_any($this->retryableExceptions, static fn($exceptionClass) => $e instanceof $exceptionClass);
    }

    private function applyJitter(int $delayMs): int
    {
        if (!$this->jitter || $delayMs <= 0) {
            return max(0, $delayMs);
        }

        $jitterRange = (int) ($delayMs * 0.25);

        return max(0, $delayMs + random_int(-$jitterRange, $jitterRange));
    }

    private function sleep(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
