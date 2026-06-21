<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Factory;

use Componenta\CQRS\Command\Metadata\CommandAttributeProviderInterface;
use Componenta\CQRS\Command\Middleware\RetryMiddleware;
use Psr\Container\ContainerInterface;

final class RetryMiddlewareFactory
{
    public function __invoke(ContainerInterface $container): RetryMiddleware
    {
        return new RetryMiddleware(
            attributes: $container->has(CommandAttributeProviderInterface::class)
                ? $container->get(CommandAttributeProviderInterface::class)
                : null,
        );
    }
}
