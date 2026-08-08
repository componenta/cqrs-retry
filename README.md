# Componenta CQRS Retry

Retry middleware package for `componenta/cqrs` commands marked with `#[Componenta\CQRS\Retry\Attribute\Retry]`.

```bash
composer require componenta/cqrs-retry
```

Register the provider and add `RetryMiddleware` to the command middleware chain where the application needs retry behavior.

```php
return [
    new Componenta\CQRS\ConfigProvider(),
    new Componenta\CQRS\Retry\ConfigProvider(),
];
```


The provider registers `Componenta\CQRS\Retry\Attribute\Retry` in `ConfigKey::COMMAND_METADATA_ATTRIBUTES`. With `componenta/cqrs-app`, its constructor arguments are compiled into the versioned map; the middleware reads them through `CommandMetadataProviderInterface` without hard-coded compiler support.
The package provides:

- `Componenta\CQRS\Command\Middleware\RetryMiddleware`
- `Componenta\CQRS\Command\Exception\RetryableExceptionInterface`