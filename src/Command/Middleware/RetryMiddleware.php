<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Middleware;

use Componenta\CQRS\Command\Exception\RetryableExceptionInterface;
use Componenta\CQRS\Command\Metadata\CommandMetadataProviderInterface;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Retry\Attribute\Retry;
use InvalidArgumentException;
use Throwable;

/**
 * Retries command execution on transient failures.
 *
 * Retries exceptions that either implement RetryableExceptionInterface or are
 * explicitly listed in the constructor. Commands must be marked with #[Retry].
 *
 * Retry runs after authorization and before transaction middleware so every
 * attempt has an independent transaction boundary.
 */
#[MiddlewareOrder(
    before: ['Componenta\\CQRS\\Command\\Middleware\\TransactionMiddleware'],
    after: ['Componenta\\CQRS\\Command\\Middleware\\PolicyMiddleware'],
)]
final readonly class RetryMiddleware implements MiddlewareInterface
{
    /** @var list<class-string<Throwable>> */
    private array $retryableExceptions;

    /**
     * @param array<array-key, mixed> $retryableExceptions
     *        Additional exception classes to retry (besides RetryableExceptionInterface)
     * @param bool $jitter Add random jitter to delay (±25%) to prevent thundering herd
     */
    public function __construct(
        private CommandMetadataProviderInterface $metadata,
        array $retryableExceptions = [],
        private bool $jitter = true,
    ) {
        if (!array_is_list($retryableExceptions)) {
            throw new InvalidArgumentException('Retryable exceptions must be a list.');
        }

        $validatedExceptions = [];

        foreach ($retryableExceptions as $index => $exception) {
            if (!is_string($exception)
                || trim($exception) === ''
                || !is_a($exception, Throwable::class, true)
            ) {
                throw new InvalidArgumentException(sprintf(
                    'Retryable exception at index %s must be a Throwable class or interface.',
                    (string) $index,
                ));
            }

            /** @var class-string<Throwable> $exception */
            $validatedExceptions[] = $exception;
        }

        $this->retryableExceptions = $validatedExceptions;
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
            ++$attempt;

            try {
                return $handler->handle($operation);
            } catch (Throwable $e) {
                if ($attempt >= $retry->attempts || !$this->isRetryable($e)) {
                    throw $e;
                }

                $this->sleep($this->applyJitter($delayMs));

                $delayMs = $delayMs > 0
                    && $delayMs >= $retry->maxDelayMs / $retry->multiplier
                        ? $retry->maxDelayMs
                        : (int) ($delayMs * $retry->multiplier);
            }
        }
    }

    private function isRetryable(Throwable $e): bool
    {
        if ($e instanceof RetryableExceptionInterface) {
            return true;
        }

        return array_any(
            $this->retryableExceptions,
            static fn(string $exceptionClass): bool => $e instanceof $exceptionClass,
        );
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
