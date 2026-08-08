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

it('uses command metadata and retries retryable command failures', function () {
    $metadata = new class implements CommandMetadataProviderInterface {
        public function get(object|string $command, string $attribute): ?object
        {
            return $attribute === Retry::class
                ? new Retry(attempts: 2, delayMs: 0)
                : null;
        }

        public function isKnown(object|string $command): bool
        {
            return true;
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

    $operation = (new RetryMiddleware(metadata: $metadata))->execute(
        Operation::create(new stdClass()),
        $handler,
    );

    expect($operation->result?->value)->toBe('ok')
        ->and($attempts->count())->toBe(2);
});
