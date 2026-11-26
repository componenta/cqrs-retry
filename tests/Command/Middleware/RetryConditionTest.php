<?php

declare(strict_types=1);

namespace Componenta\CQRS\Tests\RetryConditions;

use Closure;
use Componenta\CQRS\Command\Exception\RetryableExceptionInterface;
use Componenta\CQRS\Command\Exception\RetryConditionException;
use Componenta\CQRS\Command\Metadata\CommandMetadataProviderInterface;
use Componenta\CQRS\Command\Metadata\ReflectionCommandMetadataProvider;
use Componenta\CQRS\Command\Middleware\OperationHandlerInterface;
use Componenta\CQRS\Command\Middleware\RetryMiddleware;
use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Command\OperationResult;
use Componenta\CQRS\Retry\Attribute\Retry;
use Fiber;
use RuntimeException;
use stdClass;
use Throwable;
use TypeError;

#[Retry(attempts: 2, delayMs: 0, when: static function (Throwable $failure, OperationInterface $operation, int $failedAttempt): bool {
    $command = $operation->command;
    $command->decisions[] = [$failure, $operation, $failedAttempt];

    return $command->allow;
})]
final class InlineConditionCommand
{
    public array $decisions = [];

    public function __construct(public bool $allow) {}
}

trait ScopedCondition
{
    private const Closure CONDITION = self::mayRetry(...);

    private static function mayRetry(Throwable $failure, OperationInterface $operation, int $attempt): bool
    {
        $operation->command->decisions[] = [self::class, $attempt];

        return $operation->command instanceof self;
    }
}

#[Retry(attempts: 2, delayMs: 0, when: self::CONDITION)]
final class FirstScopedCommand
{
    use ScopedCondition;

    public array $decisions = [];
}

#[Retry(attempts: 2, delayMs: 0, when: self::CONDITION)]
final class SecondScopedCommand
{
    use ScopedCondition;

    public array $decisions = [];
}

final class TransientFailure extends RuntimeException implements RetryableExceptionInterface {}

function metadata(?Retry $retry): CommandMetadataProviderInterface
{
    return new readonly class ($retry) implements CommandMetadataProviderInterface {
        public function __construct(private ?Retry $retry) {}

        public function get(object|string $command, string $attribute): ?object
        {
            return $attribute === Retry::class ? $this->retry : null;
        }
    };
}

function handler(Closure $callback): OperationHandlerInterface
{
    return new readonly class ($callback) implements OperationHandlerInterface {
        public function __construct(private Closure $callback) {}

        public function handle(OperationInterface $operation): OperationInterface
        {
            return ($this->callback)($operation);
        }
    };
}

it('lets the condition admit unlisted failures and sees each exact failure and original operation before scheduling', function (): void {
    $operation = Operation::create(new stdClass(), ['tenant' => 42]);
    $success = $operation->withResult(new OperationResult('done'));
    $failures = [new RuntimeException('first'), new RuntimeException('second')];
    $events = [];
    $next = handler(static function (OperationInterface $input) use ($operation, $success, $failures, &$events): OperationInterface {
        expect($input)->toBe($operation);
        $attempt = count(array_filter($events, static fn (array $event): bool => $event[0] === 'handle')) + 1;
        $events[] = ['handle', $attempt];
        if (isset($failures[$attempt - 1])) {
            throw $failures[$attempt - 1];
        }

        return $success;
    });
    $when = static function (Throwable $failure, OperationInterface $input, int $attempt) use ($operation, $failures, &$events): bool {
        expect($failure)->toBe($failures[$attempt - 1])->and($input)->toBe($operation);
        $events[] = ['condition', $attempt];

        return true;
    };
    $attribute = new Retry(attempts: 3, delayMs: 10, multiplier: 2.0, maxDelayMs: 15, when: $when);
    expect($attribute->when)->toBe($when)->and($events)->toBe([]);
    $middleware = new RetryMiddleware(
        metadata($attribute),
        jitter: false,
        sleep: static function (int $delay) use (&$events): void { $events[] = ['sleep', $delay]; },
    );

    expect($middleware->execute($operation, $next))->toBe($success)
        ->and($events)->toBe([
            ['handle', 1], ['condition', 1], ['sleep', 10],
            ['handle', 2], ['condition', 2], ['sleep', 15],
            ['handle', 3],
        ]);
});

it('lets false veto marker and configured failures without scheduling another attempt', function (bool $marker): void {
    $failure = $marker ? new TransientFailure() : new RuntimeException();
    $calls = 0;
    $decisions = 0;
    $next = handler(static function () use (&$calls, $failure): never { ++$calls; throw $failure; });
    $middleware = new RetryMiddleware(
        metadata(new Retry(when: static function () use (&$decisions): bool { ++$decisions; return false; })),
        retryableExceptions: [RuntimeException::class],
        sleep: static function (): never { throw new RuntimeException('Unexpected sleep'); },
        randomInt: static function (): never { throw new RuntimeException('Unexpected jitter'); },
    );

    try {
        $middleware->execute(Operation::create(new stdClass()), $next);
        test()->fail('Expected the original handler failure.');
    } catch (Throwable $caught) {
        expect($caught)->toBe($failure);
    }
    expect($calls)->toBe(1)->and($decisions)->toBe(1);
})->with(['marker' => [true], 'configured' => [false]]);

it('does not classify a successful attempt or a failure at the total attempt bound', function (int $limit, bool $succeeds): void {
    $failure = new RuntimeException();
    $calls = 0;
    $decisions = [];
    $operation = Operation::create(new stdClass());
    $middleware = new RetryMiddleware(metadata(new Retry(
        attempts: $limit,
        delayMs: 0,
        when: static function (Throwable $input, OperationInterface $operation, int $attempt) use (&$decisions): bool {
            $decisions[] = $attempt;

            return true;
        },
    )));
    $next = handler(static function (OperationInterface $input) use (&$calls, $failure, $succeeds): OperationInterface {
        ++$calls;
        if (!$succeeds) {
            throw $failure;
        }

        return $input;
    });
    if ($succeeds) {
        expect($middleware->execute($operation, $next))->toBe($operation);
    } else {
        try {
            $middleware->execute($operation, $next);
            test()->fail('Expected the last handler failure.');
        } catch (Throwable $caught) {
            expect($caught)->toBe($failure);
        }
    }
    expect($calls)->toBe($succeeds ? 1 : $limit)
        ->and($decisions)->toBe(!$succeeds && $limit > 1 ? range(1, $limit - 1) : []);
})->with(['success' => [3, true], 'single attempt' => [1, false], 'exhausted' => [3, false]]);

it('preserves both failures when a condition throws after reassigning all argument slots', function (): void {
    $primary = new TransientFailure('private handler details');
    $secondary = new RuntimeException('private condition details');
    $operation = Operation::create(new stdClass());
    $calls = 0;
    $decisions = 0;
    $middleware = new RetryMiddleware(
        metadata(new Retry(when: static function (&$failure, &$input, &$attempt) use ($primary, $secondary, $operation, &$decisions): never {
            expect($failure)->toBe($primary)->and($input)->toBe($operation)->and($attempt)->toBe(1);
            ++$decisions;
            $failure = new RuntimeException('replacement');
            $input = null;
            $attempt = 999;
            throw $secondary;
        })),
        retryableExceptions: [Throwable::class],
        sleep: static function (): never { throw new RuntimeException('Unexpected sleep'); },
        randomInt: static function (): never { throw new RuntimeException('Unexpected jitter'); },
    );
    $next = handler(static function () use ($primary, &$calls): never { ++$calls; throw $primary; });

    try {
        $middleware->execute($operation, $next);
        test()->fail('Expected a terminal condition failure.');
    } catch (RetryConditionException $caught) {
        expect($caught->primaryFailure)->toBe($primary)
            ->and($caught->conditionFailure)->toBe($secondary)
            ->and($caught->getPrevious())->toBe($primary)
            ->and($caught->getMessage())->toContain('attempt 1')
            ->not->toContain('private handler details', 'private condition details', '999');
    }
    expect($calls)->toBe(1)->and($decisions)->toBe(1);
});

it('rejects non-boolean condition results without a truthiness conversion or fallback', function (mixed $value): void {
    $primary = new TransientFailure();
    $calls = 0;
    $middleware = new RetryMiddleware(
        metadata(new Retry(when: static fn () => $value)),
        sleep: static function (): never { throw new RuntimeException('Unexpected sleep'); },
        randomInt: static function (): never { throw new RuntimeException('Unexpected jitter'); },
    );
    try {
        $middleware->execute(Operation::create(new stdClass()), handler(static function () use ($primary, &$calls): never {
            ++$calls;
            throw $primary;
        }));
        test()->fail('Expected an invalid condition result.');
    } catch (RetryConditionException $caught) {
        expect($caught->primaryFailure)->toBe($primary)
            ->and($caught->getPrevious())->toBe($primary)
            ->and($caught->conditionFailure)->toBeInstanceOf(TypeError::class)
            ->and($caught->conditionFailure->getMessage())->toContain('bool');
    }
    expect($calls)->toBe(1);
})->with(['zero' => [0], 'one' => [1], 'string' => ['true'], 'null' => [null], 'array' => [[]], 'object' => [new stdClass()]]);

it('preserves native signature errors as terminal condition failures', function (): void {
    $primary = new RuntimeException();
    $middleware = new RetryMiddleware(metadata(new Retry(when: static function (int $failure): bool { return true; })));
    try {
        $middleware->execute(Operation::create(new stdClass()), handler(static fn (): never => throw $primary));
        test()->fail('Expected the callback signature to fail.');
    } catch (RetryConditionException $caught) {
        expect($caught->primaryFailure)->toBe($primary)
            ->and($caught->conditionFailure)->toBeInstanceOf(TypeError::class);
    }
});

it('retries the original operation and keeps the attempt bound when a successful condition reassigns its inputs', function (): void {
    $operation = Operation::create(new stdClass());
    $primary = new RuntimeException();
    $calls = 0;
    $decisions = [];
    $middleware = new RetryMiddleware(metadata(new Retry(attempts: 3, delayMs: 0, when:
        static function (&$failure, &$input, &$attempt) use (&$decisions): bool {
            $decisions[] = $attempt;
            $failure = null;
            $input = Operation::create(new stdClass());
            $attempt = 0;

            return true;
        },
    )));
    try {
        $middleware->execute($operation, handler(static function (OperationInterface $input) use ($operation, $primary, &$calls): never {
            expect($input)->toBe($operation);
            ++$calls;
            throw $primary;
        }));
        test()->fail('Expected the attempt bound to remain effective.');
    } catch (Throwable $caught) {
        expect($caught)->toBe($primary);
    }
    expect($calls)->toBe(3)->and($decisions)->toBe([1, 2]);
});

it('materializes native conditions without executing and uses the current command on repeated lookup', function (): void {
    $provider = new ReflectionCommandMetadataProvider();
    $allow = new InlineConditionCommand(true);
    $deny = new InlineConditionCommand(false);
    $first = $provider->get($allow, Retry::class);
    $again = $provider->get($allow, Retry::class);
    expect($first->when)->toBeInstanceOf(Closure::class)
        ->and($again)->not->toBe($first)
        ->and($allow->decisions)->toBe([]);
    $middleware = new RetryMiddleware($provider);
    foreach ([$allow, $deny, $allow] as $command) {
        $calls = 0;
        $primary = new RuntimeException();
        $operation = Operation::create($command);
        $next = handler(static function (OperationInterface $input) use (&$calls, $primary): OperationInterface {
            if (++$calls === 1) { throw $primary; }

            return $input;
        });
        if ($command->allow) {
            expect($middleware->execute($operation, $next))->toBe($operation)->and($calls)->toBe(2);
        } else {
            try { $middleware->execute($operation, $next); test()->fail('Expected condition veto.'); }
            catch (Throwable $caught) { expect($caught)->toBe($primary)->and($calls)->toBe(1); }
        }
        expect($command->decisions[array_key_last($command->decisions)])->toBe([$primary, $operation, 1]);
    }
    expect($allow->decisions)->toHaveCount(2)->and($deny->decisions)->toHaveCount(1);
});

it('preserves private static callable scope across trait consumers and repeated metadata resolution', function (): void {
    $provider = new ReflectionCommandMetadataProvider();
    $middleware = new RetryMiddleware($provider);
    $commands = [new FirstScopedCommand(), new SecondScopedCommand()];
    foreach ([$commands[0], $commands[1], $commands[1], $commands[0]] as $command) {
        $provider->get($command, Retry::class);
        $calls = 0;
        $operation = Operation::create($command);
        $result = $middleware->execute($operation, handler(static function (OperationInterface $input) use (&$calls): OperationInterface {
            if (++$calls === 1) { throw new RuntimeException(); }

            return $input;
        }));
        expect($result)->toBe($operation)->and($calls)->toBe(2);
    }
    expect($commands[0]->decisions)->toBe([[FirstScopedCommand::class, 1], [FirstScopedCommand::class, 1]])
        ->and($commands[1]->decisions)->toBe([[SecondScopedCommand::class, 1], [SecondScopedCommand::class, 1]]);
});

it('keeps failure decisions and attempt counts independent when a condition suspends its Fiber', function (): void {
    $first = Operation::create((object) ['name' => 'first']);
    $second = Operation::create((object) ['name' => 'second']);
    $failure = new RuntimeException();
    $decisions = [];
    $attempts = [];
    $middleware = new RetryMiddleware(metadata(new Retry(attempts: 2, delayMs: 0, when:
        static function (Throwable $input, OperationInterface $operation, int $attempt) use ($first, $failure, &$decisions): bool {
            expect($input)->toBe($failure);
            $decisions[] = [$operation, $attempt];
            if ($operation === $first) { Fiber::suspend('deciding'); }

            return true;
        },
    )));
    $next = handler(static function (OperationInterface $input) use ($failure, &$attempts): OperationInterface {
        $name = $input->command->name;
        $attempts[$name] = ($attempts[$name] ?? 0) + 1;
        if ($attempts[$name] === 1) { throw $failure; }

        return $input;
    });
    $fiber = new Fiber(static fn (): OperationInterface => $middleware->execute($first, $next));
    expect($fiber->start())->toBe('deciding')
        ->and($middleware->execute($second, $next))->toBe($second);
    $fiber->resume();

    expect($fiber->getReturn())->toBe($first)
        ->and($attempts)->toBe(['first' => 2, 'second' => 2])
        ->and($decisions)->toBe([[$first, 1], [$second, 1]]);
});

it('does not reclassify scheduler failures as handler or condition failures', function (bool $randomFails): void {
    $schedulerFailure = new RuntimeException('scheduler failed');
    $calls = 0;
    $decisions = 0;
    $middleware = new RetryMiddleware(
        metadata(new Retry(when: static function () use (&$decisions): bool { ++$decisions; return true; })),
        sleep: static fn (): never => throw $schedulerFailure,
        randomInt: static function () use ($randomFails, $schedulerFailure): int {
            if ($randomFails) { throw $schedulerFailure; }

            return 0;
        },
    );
    try {
        $middleware->execute(Operation::create(new stdClass()), handler(static function () use (&$calls): never {
            ++$calls;
            throw new RuntimeException('handler failed');
        }));
        test()->fail('Expected scheduling to fail.');
    } catch (Throwable $caught) {
        expect($caught)->toBe($schedulerFailure);
    }
    expect($calls)->toBe(1)->and($decisions)->toBe(1);
})->with(['sleeper' => [false], 'random source' => [true]]);
