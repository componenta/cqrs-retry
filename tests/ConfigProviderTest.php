<?php

declare(strict_types=1);

use Componenta\Config\ConfigKey as DependencyConfigKey;
use Componenta\CQRS\Command\Factory\RetryMiddlewareFactory;
use Componenta\CQRS\Command\Middleware\RetryMiddleware;
use Componenta\CQRS\Retry\ConfigProvider;

it('registers retry middleware factory', function (): void {
    $config = (new ConfigProvider())();
    $factories = $config[DependencyConfigKey::DEPENDENCIES][DependencyConfigKey::FACTORIES];

    expect($factories)->toMatchArray([
        RetryMiddleware::class => RetryMiddlewareFactory::class,
    ]);
});
