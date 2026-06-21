<?php

declare(strict_types=1);

namespace Componenta\CQRS\Retry;

use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\CQRS\Command\Middleware\RetryMiddleware;

final class ConfigProvider extends BaseConfigProvider
{
    protected function getFactories(): array
    {
        return [
            RetryMiddleware::class => \Componenta\CQRS\Command\Factory\RetryMiddlewareFactory::class,
        ];
    }
}
