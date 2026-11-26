# Per-command retry conditions

**Readiness:** `blocked` — reconcile the unavailable linked design discussion and settle the proposed retry-condition policy before implementation; any mandatory CQRS5/DI6 migration requires its own settled dependency transition.

## Objective

Allow a command's `#[Retry]` declaration to decide whether a failed downstream attempt may be repeated using a PHP closure that can inspect the failure and current operation. Attempt limits and scheduling remain owned by the retry middleware.

This is a specification-only draft. Exact callback semantics below are proposed decisions; they are not attributed to the user until reconciled with the linked conversation. No implementation or test changes are authorized by this document alone.

## Evidence and scope

The inspected package baseline is `componenta/cqrs-retry` `main` at `f9896582ee73e3348d7cc92b9c8b681ca245e280`. Its `v2` branch is an ancestor of this baseline. No `AGENTS.md` or existing specification convention was present in the inspected repository tree.

The package currently requires PHP `>=8.4`, `componenta/cqrs:^4.0.0`, and `componenta/config:^3.0.0`. Released CQRS `v4.0.0` is commit `3e49a7dd8d0497d980cb93bb2a884d924710bcc9`. Separately inspected CQRS `main` at `b2306d6503a6ae1d010c160b24216dc830a7c31a` is v5, requires PHP `^8.5.13` and DI `^6.0`, and cannot satisfy the add-on's current CQRS constraint.

The required linked design discussion was unavailable during preparation. Its contents have not been incorporated. Its exact decisions can supersede the proposed policy below.

In scope: the optional condition on `Retry`, condition evaluation during the existing synchronous retry flow, its error contract, and verification through real middleware attempts.

Out of scope: asynchronous redelivery, retry persistence, new backoff algorithms, new default retry counts or delays, configurable delay callbacks, implicit transaction/lock reordering, arbitrary callback service resolution, and making non-idempotent work safe to repeat.

## Current architecture

- `src/Retry/Attribute/Retry.php` is a final readonly class-target attribute with `attempts`, `delayMs`, `multiplier`, and `maxDelayMs`. Existing defaults are 3 total attempts, 100 milliseconds initial delay, multiplier 1.0, and 10,000 milliseconds maximum delay.
- `src/Command/Middleware/RetryMiddleware.php` obtains metadata once per `execute()`, then passes the same input `OperationInterface` to the downstream handler on each attempt. A successful returned operation is returned immediately.
- A caught downstream `Throwable` is eligible when it implements `RetryableExceptionInterface` or matches an explicitly configured exception class/interface. The attempt limit is checked before another wait.
- Waiting and random jitter already have injectable closure seams. The default wait uses `usleep()`; zero delay invokes neither the sleeper nor the jitter source. Delay arithmetic and the cap prevent overflow into microseconds.
- `src/Command/Factory/RetryMiddlewareFactory.php` resolves only the metadata provider and constructs the middleware with the existing defaults. The new attribute callback requires no additional container service.

## Requirements and preservation

- **R1:** A command may choose retry eligibility using its current failure and explicit operation context.
- **R2:** The condition runs only when a downstream attempt failed and another attempt remains.
- **R3:** The callback's decision is boolean, with exact documented behavior for false, invalid values, and thrown failures.
- **I1:** `attempts` is an upper bound on total downstream handler invocations, including the initial call. A callback cannot bypass it.
- **I2:** No condition result is reused for another failure, command, operation, Fiber, or dispatch.
- **P1:** An absent `Retry` attribute invokes the handler once and does not classify or delay a failure.
- **P2:** A null condition preserves the existing marker/configured-list classification, exact original failure propagation, backoff, cap, jitter, and waiting behavior.
- **P3:** Each attempt receives the original operation passed to this middleware entry; the success result is the exact operation returned by the successful handler.
- **P4:** The application owns pipeline topology. No new transaction, lock, policy pass, asynchronous retry, or automatic idempotency key is inserted.

## Proposed desired-state architecture

Keep retry policy in `cqrs-retry`. The attribute carries a decision function; the middleware owns when to invoke it and whether another attempt is still allowed. The callback does not sleep, alter scheduling, or call the downstream handler on the middleware's behalf.

Do not introduce a condition registry, a strategy interface with one implementation, a container argument resolver, or a generic callback execution subsystem for this feature. Native closure invocation is sufficient for this boundary.

### Proposed policy: an explicit condition replaces the default classifier

Proposed selection rule:

```text
if the failed attempt exhausts attempts:
    rethrow the same downstream failure
else if Retry.when is null:
    retry only when the existing marker/configured-list classifier accepts
else:
    retry only when Retry.when returns true
```

An explicit per-command callback is therefore the complete eligibility rule for that command; it may allow a failure not admitted by the default list and may deny an otherwise retryable failure. The attempt bound and scheduler remain mandatory.

This policy is proposed because a logical AND gate cannot admit an application-specific transient failure without also changing middleware-global configuration, while a logical OR rule cannot veto an overly broad configured class. Those are meaningful alternatives, not equivalent implementations. The linked conversation must settle whether replacement or an additional AND gate is intended. Do not silently select one while coding.

The null/default path and replacement path are mutually exclusive. There is no fallback to the default classifier after false, an invalid return, or a callback exception.

## Proposed public contracts

### `Retry::when`

Append an optional constructor argument so existing positional argument meanings remain unchanged:

```php
/** @param null|(Closure(Throwable, OperationInterface, int): bool) $when */
public function __construct(
    public int $attempts = 3,
    int $delayMs = 100,
    public float $multiplier = 1.0,
    public int $maxDelayMs = 10000,
    public ?Closure $when = null,
);
```

The existing validation of attempts, delay, multiplier, and maximum delay remains applicable. Constructing or materializing the attribute stores the closure without calling it. Callback bodies are not executed during map building or service construction.

The callback is invoked positionally as:

```php
($retry->when)($failure, $operation, $failedAttempt);
```

- `$failure` is the exact throwable from the failed downstream `handle()` call; it is not unwrapped, converted, or replaced.
- `$operation` is the exact input operation supplied to this middleware entry. Its command is available through `$operation->command`, and correlation/idempotency information remains explicit through the operation's public contract. The middleware does not replace its attributes or result.
- `$failedAttempt` is the one-based number of the invocation that just failed. It is 1 after the first failure. It is not the number of the upcoming attempt or retries already performed.
- The callback must return an actual boolean. An integer, string, null, object, or other truthy/falsy value is invalid; do not cast it.

The closure is called once for each eligible decision point: after a failed attempt, below the total-attempt limit, and before jitter or waiting. It is never called before the initial attempt, after success, on the final allowed failure, for missing metadata, or after a scheduling failure. Each decision uses the current caught failure; decisions are not memoized.

On false, throw the same downstream failure object without sleeping, sampling jitter, or making another attempt. On true, use the existing scheduling path and retry with the original operation.

### Condition failures

Proposed new public exception: `Componenta\CQRS\Command\Exception\RetryConditionException`, extending `RuntimeException` and exposing:

```php
public readonly Throwable $primaryFailure;
public readonly Throwable $conditionFailure;

public function __construct(
    Throwable $primaryFailure,
    Throwable $conditionFailure,
    int $failedAttempt,
);
```

`primaryFailure` is the exact handler throwable being classified. `conditionFailure` is the exact callback throwable, including a parameter or declared return-type `TypeError`. If an untyped callback returns a non-boolean value, create a `TypeError` describing the required boolean return and use it as `conditionFailure`.

Set the wrapper's `previous` to `primaryFailure`, matching the existing ecosystem convention of preserving the failed operation as the primary causal chain while retaining a secondary failure explicitly. The wrapper message names the failed attempt and decision contract without copying operation attributes or command payloads.

A condition failure is terminal for this middleware entry. Do not send it back through `when`, the default retry classifier, or the same retry loop; do not call the sleeper or random source. A separately configured outer retry layer is outside this middleware's control and is not silently disabled.

Metadata-construction failures retain the metadata provider's error type. Failures from the existing random or sleep dependencies propagate as scheduling failures and are not classified as handler failures.

### Example of the proposed API

```php
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Retry\Attribute\Retry;

#[Retry(when: self::mayRepeat(...))]
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

`TemporaryReadFailure` is an application-defined throwable in this example. The example deliberately does not invent a new retry count or delay policy.

For an inline callback in PHP 8.5 attribute arguments, use a capture-free `static function (...) : bool { ... }`. Neither `fn` nor `static fn` is permitted in that constant-expression position. PHP's native first-class function/static-method scope must be preserved; do not rebuild a private method callback from strings.

Although the syntax is available from PHP 8.5.0, the proposed native first-class-callable attribute scope contract is supported and must be tested on PHP 8.5.13 or later. PHP 8.5.13 fixes trait constant-expression callables resolving against the first consuming class and bypassing visibility checks.

## State, topology, and safety of repeated work

The middleware retains the existing synchronous, per-entry attempt counter and delay sequence. It does not persist failure decisions or attempt progress. Restarting a process or redelivering an asynchronous operation is governed by another owner and is not covered by the in-memory attempt bound.

The same operation object is supplied on retries, but the operation's immutability is shallow. The package cannot restore external state, reverse a command object's mutations, roll back external I/O, or prove that an indeterminate provider failure is safe to retry. The application condition must classify only operations it is prepared to repeat under the chosen pipeline topology.

With retry outside transaction middleware, each attempt enters the transaction middleware again. With a transaction outside retry, attempts share the enclosing transaction. With lock outside retry, one acquisition encloses the attempts; with retry outside lock, each attempt enters lock resolution/acquisition again. This feature does not choose or reject a topology.

## Metadata and generated artifacts

The command source declaration is authoritative. Released CQRS v4 calls native `newInstance()` for every metadata lookup. CQRS v5 caches reflection descriptors, not instantiated attribute arguments, and still materializes the attribute on each lookup. Retry middleware retrieves metadata once for its entry and uses that obtained condition during its attempt loop; it does not cache the condition across entries.

Native closure scope and lifetime are preserved. Do not promise a reset of arbitrary static callback state beyond native PHP/provider behavior. Application callbacks must not rely on the middleware to isolate process-global state.

The inspected `cqrs-app` builder exports command/query handler and listener maps, not `Retry` metadata. Do not add callback serialization or change that generated map format. No queue payload, database migration, or command serialization format is changed by adding class metadata.

## Contract impact and technical change surface

| Source or consumer | Intended disposition |
|---|---|
| `src/Retry/Attribute/Retry.php` | Append `?Closure $when = null`, with its exact callback contract. |
| `src/Command/Middleware/RetryMiddleware.php` | Evaluate the chosen eligibility rule at a failed-attempt decision point; preserve the scheduler and operation identity. |
| New `src/Command/Exception/RetryConditionException.php` | Preserve both the downstream failure and a condition-contract failure. This is a proposed new file, not an existing source. |
| `src/Command/Exception/RetryableExceptionInterface.php` | Retain as the default/null-condition marker; document explicit-condition precedence. |
| `src/Command/Factory/RetryMiddlewareFactory.php` and `src/Retry/ConfigProvider.php` | No new service or container lookup is needed. |
| Existing `#[Retry]` declarations and middleware exception lists | Keep their existing behavior while `when` is null. |
| Third-party metadata providers | May construct the extended attribute; no new provider method is introduced. |
| CQRS app maps / transport payloads | No schema or serialization change. |

The implementation will update `tests/RetryAttributeTest.php`, `tests/Command/Middleware/RetryMiddlewareTest.php`, relevant native attribute fixtures in the existing test tree, and `README.md`. No Composer transition is proposed for the current CQRS4-compatible target; a required CQRS5/DI6 transition must be specified separately before implementation. None of these files are modified during specification work.

## Verification strategy

Use the declared Pest test framework and public `execute()` behavior. Use the existing `sleep` and `randomInt` seams to record actual scheduling without real waiting. A recording downstream handler can throw distinct failure instances on successive calls and then return a known operation. Attribute/native-provider integration must use real PHP attributes rather than mocking reflection internals.

| Contract | Observable acceptance proof |
|---|---|
| R1, replacement policy | A true condition admits an unlisted throwable; a false condition vetoes a marker/configured throwable. No default-classifier fallback occurs. |
| R2, I1 | `attempts: 1` never calls `when`; final allowed failures never call it; a sequence exposes `failedAttempt` values 1, 2, ... only for decisions with another attempt remaining. |
| R3 | False rethrows the same handler throwable and records no wait/jitter/subsequent call. True enters the existing wait path. Non-boolean results are not coerced. |
| Condition failure contract | A callback exception or signature type error produces `RetryConditionException` with both exact failures; a non-boolean result produces the documented TypeError cause. No further condition/sleep/random/handler call occurs. |
| P1 | Missing metadata delegates once and propagates the original failure. |
| P2 | Null conditions preserve marker and explicit-list acceptance, rejection of unrelated failures, existing capped backoff, jitter endpoints, zero-delay behavior, and exhaustion behavior. |
| P3 | Callback and every downstream attempt receive the same original operation; success returns the exact handler result operation. |
| I2 | Two failures with different classifications are evaluated separately; successive operations of the same command class use their own command/context, including repeated native metadata lookups. |
| R2, R3 | Successful first calls, missing metadata, and metadata materialization do not execute the condition; scheduling-dependency failures are not fed back into the condition. |
| Native scope | On PHP 8.5.13+, inline static functions and first-class private static methods work in command-class attributes. Two command classes using the same trait and private static first-class callbacks retain their own class scope and visibility through first and repeated metadata resolution; scope is not reconstructed from strings. |
| P4 | Existing transaction/lock topology outcomes are retained; the callback changes only eligibility, never the number or placement of wrappers. |

For TDD, first demonstrate the unsatisfied new behavior through the public middleware contract, implement a coherent slice, then rerun the relevant existing checks. Use datasets for return-type cases and distinct failure objects to prove exception identity. Avoid tests that mirror a private classifier helper or private delay arithmetic. Use `composer test` and `composer analyse`, the package's verified scripts, after the selected dependency target is installable. No runtime checks have been executed during this draft.

## Blocking decisions and bounded evidence work

1. **Linked-conversation requirements.** Extract relevant constraints from the referenced conversation and reconcile them with scope, callback input, classifier precedence, and failure behavior. Do not attribute a missing transcript's requirements to the user. Stop once every material conflict has an explicit resolution.
2. **Retry policy.** Decide replacement classification versus an additional AND gate, the positional callback inputs/one-based attempt meaning, and terminal callback-failure projection. This draft proposes a complete replacement classifier and a dual-failure exception; those choices must be accepted or revised before production work. The deciding evidence is the user/context intent and existing consumer requirements, not a performance benchmark.
3. **Dependency reconciliation.** The proposed feature targets the current package line and retains its CQRS4-compatible constraints. Its class definitions can remain compatible with PHP 8.4, but the supported native callable attribute scope contract and its acceptance fixtures require PHP 8.5.13+. Retaining package-wide PHP 8.4 for existing/programmatic usage versus raising the package minimum remains a dependency-reconciliation decision; syntax availability from PHP 8.5.0 does not settle that choice. If the unavailable discussion mandates DI6/CQRS5 or a higher library-wide PHP minimum, treat that as a separate migration prerequisite and settle the Composer transition and supported integration pair explicitly. The current add-on constraint rejects CQRS5. Standalone metadata compatibility does not prove that the whole dependency graph installs.

No production slices are executable while these blockers remain. Once settled, derive vertical slices for condition-driven attempts, terminal condition failures, and metadata/composition parity using the acceptance table. After implementation, compare the resulting behavior and dependency graph against the normative target; resolve missing, partial, contradictory, or unrequested behavior before completion.

## Sources

- [Retry attribute at the inspected package baseline](https://github.com/componenta/cqrs-retry/blob/f9896582ee73e3348d7cc92b9c8b681ca245e280/src/Retry/Attribute/Retry.php).
- [Existing retry decision and scheduling owner](https://github.com/componenta/cqrs-retry/blob/f9896582ee73e3348d7cc92b9c8b681ca245e280/src/Command/Middleware/RetryMiddleware.php).
- [Existing public retry tests](https://github.com/componenta/cqrs-retry/blob/f9896582ee73e3348d7cc92b9c8b681ca245e280/tests/Command/Middleware/RetryMiddlewareTest.php).
- [CQRS4 operation contract](https://github.com/componenta/cqrs/blob/3e49a7dd8d0497d980cb93bb2a884d924710bcc9/src/Command/OperationInterface.php), [CQRS4 native metadata materialization](https://github.com/componenta/cqrs/blob/3e49a7dd8d0497d980cb93bb2a884d924710bcc9/src/Command/Metadata/ReflectionCommandMetadataProvider.php).
- [CQRS5 operation contract](https://github.com/componenta/cqrs/blob/b2306d6503a6ae1d010c160b24216dc830a7c31a/src/Command/OperationInterface.php), [CQRS5 metadata cache](https://github.com/componenta/cqrs/blob/b2306d6503a6ae1d010c160b24216dc830a7c31a/src/Command/Metadata/ReflectionCommandMetadataProvider.php).
- [CQRS application map builder](https://github.com/componenta/cqrs-app/blob/ec60fadebd5eab6a7582a930311e3258fb31df37/src/Build/CqrsBuilder.php).
- [PHP 8.5 closures in constant expressions](https://wiki.php.net/rfc/closures_in_const_expr), [first-class callables and native scope](https://wiki.php.net/rfc/fcc_in_const_expr).
- [PHP 8.5.13 trait first-class-callable scope and visibility fix](https://www.php.net/ChangeLog-8.php#8.5.13).
