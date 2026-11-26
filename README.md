# Componenta CQRS Retry

Synchronous command retries with bounded attempts, backoff, and optional native PHP conditions in `#[Retry]`.

[Русская документация](README.ru.md)

## Requirements and registration

The v4 line requires PHP **8.5.13+**, Componenta CQRS **5**, and Componenta Config **4**.

```bash
composer require componenta/cqrs-retry
```

Register the providers and add `Componenta\CQRS\Command\Middleware\RetryMiddleware` to the command middleware chain:

```php
return [
    new Componenta\CQRS\ConfigProvider(),
    new Componenta\CQRS\Retry\ConfigProvider(),
];
```

The factory uses CQRS `CommandMetadataProviderInterface`. The default provider caches reflection descriptors and creates fresh attribute instances. The middleware reads metadata once per entry and retains no condition or decision across entries.

## Default eligibility

```php
use Componenta\CQRS\Retry\Attribute\Retry;

#[Retry(attempts: 3, delayMs: 100, multiplier: 2.0, maxDelayMs: 1000)]
final readonly class RefreshRecord
{
    public function __construct(public string $recordId) {}
}
```

With `when: null`, a failed attempt is eligible when its throwable implements `Componenta\CQRS\Command\Exception\RetryableExceptionInterface` or matches an exception class/interface in the middleware's `retryableExceptions` list. Commands without `Retry` metadata execute once.

`attempts` counts **all handler invocations, including the initial call**. Exhaustion rethrows the exact last handler throwable without another decision or wait. A successful call immediately returns the exact operation returned by the handler.

## Per-command conditions

An explicit condition **replaces** the marker/configured-list classifier. It can admit an otherwise unlisted failure or veto an otherwise retryable failure. The attempt limit still applies first.

```php
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Retry\Attribute\Retry;

final class TemporaryReadFailure extends RuntimeException {}

#[Retry(attempts: 3, when: self::mayRepeat(...))]
final readonly class RefreshExternalRecord
{
    public function __construct(public string $recordId) {}

    private static function mayRepeat(
        Throwable $failure,
        OperationInterface $operation,
        int $failedAttempt,
    ): bool {
        return $failure instanceof TemporaryReadFailure
            && $operation->command instanceof self;
    }
}
```

The callback contract is `Closure(Throwable, OperationInterface, int): bool`:

- The first argument is the exact throwable from the failed downstream attempt.
- The second is the original operation supplied to this middleware entry, including its command and attributes.
- The third is the one-based attempt that just failed: `1` after the initial call.

A condition runs once after a failed attempt only when another attempt remains, before jitter and waiting. It never runs on metadata discovery, the initial successful path, or the final allowed failure. Returning `false` rethrows the original failure without waiting; `true` uses the existing scheduler. There is no default-classifier fallback.

Results must be actual booleans. Integer, string, null, and other truthy/falsy results are invalid. Reassigning a callback argument by reference cannot replace the original failure, operation, or attempt counter. Shared objects remain mutable under ordinary PHP semantics.

For inline attribute callbacks use a capture-free `static function`, for example:

```php
#[Retry(when: static function (Throwable $failure, OperationInterface $operation, int $failedAttempt): bool {
    return $failure instanceof TemporaryReadFailure;
})]
final readonly class RefreshSnapshot {}
```

First-class function/static-method callables retain native scope, including private methods. `fn` and `static fn` are not permitted in PHP 8.5 constant expressions. PHP **8.5.13+** is required for the corrected trait first-class-callable scope and visibility behavior. Programmatic metadata accepts `?Closure`; there is no callback service lookup or argument autowiring.

## Condition failures

If the condition throws, has an incompatible signature, or returns a non-boolean value, the middleware throws `Componenta\CQRS\Command\Exception\RetryConditionException`:

| Member | Value |
|---|---|
| `primaryFailure` | Exact handler throwable being classified. |
| `conditionFailure` | Exact callback throwable, or a `TypeError` for an invalid untyped result. |
| `getPrevious()` | The primary handler throwable. |

The exception message identifies the failed attempt and boolean decision contract without copying command payloads or failure messages. A condition failure terminates this middleware entry: no fallback classification, jitter, wait, or further handler invocation. Metadata errors stay metadata errors. Sleep/random-source failures propagate as scheduling failures and are not reclassified.

## Scheduling and operation identity

| Attribute argument | Default | Contract |
|---|---|---|
| `attempts` | `3` | At least one total handler invocation. |
| `delayMs` | `100` | Initial non-negative delay in milliseconds. |
| `multiplier` | `1.0` | Finite multiplier of at least one. |
| `maxDelayMs` | `10000` | Cap at least `delayMs`, safe for microsecond conversion. |
| `when` | `null` | Optional replacement classifier. |

The middleware's `jitter` option defaults to `true` and adds up to ±25%, bounded by the delay cap. Zero delay skips waiting and random sampling. The constructor has optional `sleep(int $milliseconds): void` and `randomInt(int $min, int $max): int` closures; defaults use `usleep()` and `random_int()`. The registered factory uses the defaults.

Every attempt receives the original operation. Its immutability is shallow: command mutations, external I/O, and transaction effects are not undone. The application condition must admit only work it can safely repeat; this feature adds no automatic idempotency key or redelivery persistence.

## Middleware order

The application controls topology. With retry outside transaction middleware, each attempt enters a fresh transaction middleware invocation. With transaction outside retry, attempts share the enclosing transaction.

With lock outside retry, one acquisition surrounds the attempts. With retry outside lock, each attempt resolves/acquires/releases its own lock. The condition changes eligibility and does not insert or reorder middleware.

## Development

Run `composer test` and `composer analyse`. See the [implementation specification](docs/specifications/attribute-callbacks.md) for acceptance criteria and [integration verification](docs/development/integration.md) for dependency revisions and reproducible development setup.
