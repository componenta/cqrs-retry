<?php

declare(strict_types=1);

use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigKey;
use Componenta\Config\Environment;
use Componenta\CQRS\Command\Middleware\OperationHandlerInterface;
use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Command\OperationResult;
use Componenta\DI\ContainerFactory;
use Componenta\CQRS\Command\Middleware\RetryMiddleware;
use Componenta\CQRS\Command\Exception\RetryableExceptionInterface;
use Componenta\CQRS\Retry\Attribute\Retry;
use Componenta\CQRS\Retry\ConfigProvider;

#[Retry(attempts: 2, delayMs: 0)]
final class RetryProviderCommand
{
}
final class RetryProviderFailure extends RuntimeException implements RetryableExceptionInterface
{
}

it('uses reflection metadata through real provider wiring without a map', function (): void {
    $composition = (new ConfigFactory())->create(new Environment([]), new \Componenta\CQRS\ConfigProvider(), new ConfigProvider());
    $container = (new ContainerFactory())->create($composition->config, $composition->dependencies);
    $handler = new class () implements OperationHandlerInterface {
        public int $calls = 0;
        public function handle(OperationInterface $operation): OperationInterface
        {
            if (++$this->calls === 1) {
                throw new RetryProviderFailure();
            }
            return $operation->withResult(new OperationResult('retried'));
        }
    };
    $result = $container->get(RetryMiddleware::class)->execute(Operation::create(new RetryProviderCommand()), $handler);
    expect($result->result?->value)->toBe('retried')->and($handler->calls)->toBe(2);
});
