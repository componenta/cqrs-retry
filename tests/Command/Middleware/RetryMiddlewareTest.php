<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Attribute\Async;
use Componenta\CQRS\Command\Attribute\Lock;
use Componenta\CQRS\Command\Attribute\Retry;
use Componenta\CQRS\Command\Exception\RetryableExceptionInterface;
use Componenta\CQRS\Command\Metadata\CommandAttributeProviderInterface;
use Componenta\CQRS\Command\Middleware\OperationHandlerInterface;
use Componenta\CQRS\Command\Middleware\RetryMiddleware;
use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Command\OperationResult;

final class CqrsRetryableTestException extends RuntimeException implements RetryableExceptionInterface {}

it('uses the attribute provider and retries retryable command failures', function () {
    $attributes = new class implements CommandAttributeProviderInterface {
        public function async(object|string $command): ?Async
        {
            return null;
        }

        public function retry(object|string $command): ?Retry
        {
            return new Retry(attempts: 2, delayMs: 0);
        }

        public function lock(object|string $command): ?Lock
        {
            return null;
        }
    };

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

    $operation = (new RetryMiddleware(attributes: $attributes))->execute(
        Operation::create(new stdClass()),
        $handler,
    );

    expect($operation->result?->value)->toBe('ok')
        ->and($attempts->count())->toBe(2);
});
