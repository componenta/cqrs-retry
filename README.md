# Componenta CQRS Retry

Retry middleware for CQRS v4 commands marked with `#[Componenta\CQRS\Retry\Attribute\Retry]`.

```bash
composer require componenta/cqrs-retry
```

Register the CQRS and retry providers and add `RetryMiddleware` to the command middleware chain where transient failures may be retried.

```php
return [
    new Componenta\CQRS\ConfigProvider(),
    new Componenta\CQRS\Retry\ConfigProvider(),
];
```

The provider registers `Componenta\CQRS\Retry\Attribute\Retry` in `ConfigKey::COMMAND_METADATA_ATTRIBUTES` and provides `RetryMiddleware`. The middleware requires the core `CommandMetadataProviderInterface`; it does not create an independent reflection fallback. With `componenta/cqrs-app`, retry metadata therefore follows the same development/compiled map semantics as the rest of CQRS.

Middleware ordering is controlled by the application. With transaction middleware, the two common compositions have different semantics:

```text
RetryMiddleware
  TransactionMiddleware
    handler
```

creates a fresh transaction for every retry attempt. By contrast:

```text
TransactionMiddleware
  RetryMiddleware
    handler
```

keeps all attempts inside one surrounding transaction. The package does not reject either topology; choose the one that matches the desired transaction semantics.

`RetryableExceptionInterface` marks transient exceptions that may be retried. Additional throwable classes can be configured explicitly in the middleware constructor. Retry attempts, delay, multiplier, maximum delay, and jitter are controlled by `#[Retry]` and middleware configuration.
