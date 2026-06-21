# Componenta CQRS Retry

Retry middleware package for `componenta/cqrs` commands marked with `#[Retry]`.

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

The package provides:

- `Componenta\CQRS\Command\Middleware\RetryMiddleware`
- `Componenta\CQRS\Command\Exception\RetryableExceptionInterface`