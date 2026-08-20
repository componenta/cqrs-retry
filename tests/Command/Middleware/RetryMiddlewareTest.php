<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Exception\RetryableExceptionInterface;
use Componenta\CQRS\Command\Metadata\CommandMetadataProviderInterface;
use Componenta\CQRS\Command\Middleware\OperationHandlerInterface;
use Componenta\CQRS\Command\Middleware\RetryMiddleware;
use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Command\OperationResult;
use Componenta\CQRS\Retry\Attribute\Retry;

final class CqrsRetryableTestException extends RuntimeException implements RetryableExceptionInterface {}

function retryTestMetadata(?Retry $retry = null): CommandMetadataProviderInterface
{
    return new class ($retry) implements CommandMetadataProviderInterface {
        public function __construct(private readonly ?Retry $retry) {}

        public function get(object|string $command, string $attribute): ?object
        {
            return $attribute === Retry::class ? $this->retry : null;
        }

        public function isKnown(object|string $command): bool
        {
            return true;
        }
    };
}

it('uses command metadata and retries retryable command failures', function () {
    $attempts = new ArrayObject();
    $handler = new class($attempts) implements OperationHandlerInterface {
        public function __construct(private readonly ArrayObject $attempts) {}

        public function handle(OperationInterface $operation): OperationInterface
        {
            $this->attempts[] = 'attempt';

            if ($this->attempts->count() === 1) {
                throw new CqrsRetryableTestException();
            }

            return $operation->withResult(new OperationResult('ok'));
        }
    };

    $operation = (new RetryMiddleware(
        metadata: retryTestMetadata(new Retry(attempts: 2, delayMs: 0)),
    ))->execute(
        Operation::create(new stdClass()),
        $handler,
    );

    expect($operation->result?->value)->toBe('ok')
        ->and($attempts->count())->toBe(2);
});

it('rejects non-finite retry multipliers', function (float $multiplier): void {
    expect(fn() => new Retry(multiplier: $multiplier))
        ->toThrow(InvalidArgumentException::class, 'finite');
})->with([NAN, INF, -INF]);

it('rejects invalid retryable exception declarations', function (array $exceptions): void {
    expect(fn() => new RetryMiddleware(retryTestMetadata(), $exceptions))
        ->toThrow(InvalidArgumentException::class, 'Throwable class or interface');
})->with([
    'non-throwable class' => [[stdClass::class]],
    'empty class' => [['']],
]);

it('rejects associative retryable exception declarations', function (): void {
    expect(fn() => new RetryMiddleware(
        retryTestMetadata(),
        ['exception' => RuntimeException::class],
    ))->toThrow(InvalidArgumentException::class, 'must be a list');
});

it('rejects delays that cannot be converted safely to microseconds', function (): void {
    expect(fn() => new Retry(maxDelayMs: PHP_INT_MAX))
        ->toThrow(InvalidArgumentException::class, 'too large');
});
