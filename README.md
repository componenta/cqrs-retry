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

When transaction middleware is present, retry must wrap the transaction boundary:

```text
RetryMiddleware
  TransactionMiddleware
    handler
```

This is a hard CQRS v4 middleware-order constraint. Constructing a command bus with `TransactionMiddleware -> RetryMiddleware` fails immediately instead of allowing writes from a failed attempt to remain in the transaction later committed by a successful attempt.

`RetryableExceptionInterface` marks transient exceptions that may be retried. Additional throwable classes can be configured explicitly in the middleware constructor. Retry attempts, delay, multiplier, maximum delay, and jitter are controlled by `#[Retry]` and middleware configuration.
