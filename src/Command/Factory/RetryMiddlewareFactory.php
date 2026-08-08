<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Factory;

use Componenta\CQRS\Command\Metadata\CommandMetadataProviderInterface;
use Componenta\CQRS\Command\Middleware\RetryMiddleware;
use LogicException;
use Psr\Container\ContainerInterface;

final class RetryMiddlewareFactory
{
    public function __invoke(ContainerInterface $container): RetryMiddleware
    {
        $metadata = null;

        if ($container->has(CommandMetadataProviderInterface::class)) {
            $metadata = $container->get(CommandMetadataProviderInterface::class);

            if (!$metadata instanceof CommandMetadataProviderInterface) {
                throw new LogicException(sprintf(
                    'Container entry "%s" must implement %s.',
                    CommandMetadataProviderInterface::class,
                    CommandMetadataProviderInterface::class,
                ));
            }
        }

        return new RetryMiddleware(
            metadata: $metadata,
        );
    }
}
