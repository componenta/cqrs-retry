<?php

declare(strict_types=1);

namespace Componenta\CQRS\Retry;

use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\CQRS\Command\Middleware\RetryMiddleware;
use Componenta\CQRS\ConfigKey;
use Componenta\CQRS\Retry\Attribute\Retry;

final class ConfigProvider extends BaseConfigProvider
{
    /**
     * @return array<string, list<class-string>>
     */
    protected function getConfig(): array
    {
        return [
            ConfigKey::COMMAND_METADATA_ATTRIBUTES => [Retry::class],
        ];
    }

    protected function getFactories(): array
    {
        return [
            RetryMiddleware::class => \Componenta\CQRS\Command\Factory\RetryMiddlewareFactory::class,
        ];
    }
}
