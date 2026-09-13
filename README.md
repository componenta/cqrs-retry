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

The provider registers `RetryMiddleware`. The middleware reads `Retry` through the core `CommandMetadataProviderInterface`. Its default Reflection implementation creates fresh metadata on each call, independently of handler maps and application environment.

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


For controlled waiting and randomness, the constructor also accepts optional closures:

- `sleep(int $milliseconds): void` waits between attempts.
- `randomInt(int $min, int $max): int` returns an integer within the inclusive range.

The defaults use PHP's `usleep()` and `random_int()`. The registered factory uses these defaults. Supplying the closures supports deterministic tests of actual retry attempts and delays without sleeping or calling private methods.
