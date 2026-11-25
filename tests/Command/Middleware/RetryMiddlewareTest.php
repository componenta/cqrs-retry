<?php

declare(strict_types=1);

use Componenta\CQRS\Command\Exception\RetryableExceptionInterface;
use Componenta\CQRS\Command\Metadata\CommandMetadataProviderInterface;
use Componenta\CQRS\Command\Middleware\OperationHandlerInterface;
use Componenta\CQRS\Command\Middleware\RetryMiddleware;
use Componenta\CQRS\Command\Operation;
use Componenta\CQRS\Command\OperationInterface;
use Componenta\CQRS\Command\OperationResult;
use Componenta\CQRS\Retry\Attribute\Retry;

final class CqrsRetryableTestException extends RuntimeException implements RetryableExceptionInterface
{
}

function retryTestMetadata(?Retry $retry = null): CommandMetadataProviderInterface
{
    return new class ($retry) implements CommandMetadataProviderInterface {
        public function __construct(private readonly ?Retry $retry)
        {
        }

        public function get(object|string $command, string $attribute): ?object
        {
            return $attribute === Retry::class ? $this->retry : null;
        }
    };
}

it('uses command metadata and retries retryable command failures', function () {
    $attempts = new ArrayObject();
    $handler = new class ($attempts) implements OperationHandlerInterface {
        public function __construct(private readonly ArrayObject $attempts)
        {
        }

        public function handle(OperationInterface $operation): OperationInterface
        {
            $this->attempts[] = 'attempt';

            if ($this->attempts->count() === 1) {
                throw new CqrsRetryableTestException();
            }

            return $operation->withResult(new OperationResult('ok'));
        }
    };

    $operation = (new RetryMiddleware(
        metadata: retryTestMetadata(new Retry(attempts: 2, delayMs: 0)),
    ))->execute(
        Operation::create(new stdClass()),
        $handler,
    );

    expect($operation->result?->value)->toBe('ok')
        ->and($attempts->count())->toBe(2);
});

it('applies capped backoff between real attempts and stops waiting after success', function (
    int $delayMs,
    float $multiplier,
    int $maxDelayMs,
    array $expectedDelays,
): void {
    $waits = [];
    $handler = new class () implements OperationHandlerInterface {
        public int $attempts = 0;
        public function handle(OperationInterface $operation): OperationInterface
        {
            if (++$this->attempts < 4) {
                throw new CqrsRetryableTestException();
            }
            return $operation->withResult(new OperationResult('completed'));
        }
    };
    $middleware = new RetryMiddleware(
        retryTestMetadata(new Retry(attempts: 4, delayMs: $delayMs, multiplier: $multiplier, maxDelayMs: $maxDelayMs)),
        jitter: false,
        sleep: static function (int $milliseconds) use (&$waits): void {
            $waits[] = $milliseconds;
        },
        randomInt: static function (int $min, int $max): int {
            throw new LogicException('Jitter is disabled.');
        },
    );

    $result = $middleware->execute(Operation::create(new stdClass()), $handler);

    expect($result->result?->value)->toBe('completed')
        ->and($handler->attempts)->toBe(4)
        ->and($waits)->toBe($expectedDelays);
})->with([
    'growing backoff' => [5, 2.0, 12, [5, 10, 12]],
    'rounding boundary' => [9, 1.2, 10, [9, 10, 10]],
    'huge finite multiplier' => [1, PHP_FLOAT_MAX, 10, [1, 10, 10]],
    'zero delay' => [0, 2.0, 10, []],
]);

it('bounds the actual retry wait at both jitter endpoints', function (bool $upper, int $expectedWait): void {
    $waits = [];
    $ranges = [];
    $handler = new class () implements OperationHandlerInterface {
        public int $attempts = 0;
        public function handle(OperationInterface $operation): OperationInterface
        {
            if (++$this->attempts === 1) {
                throw new CqrsRetryableTestException();
            }
            return $operation;
        }
    };
    $middleware = new RetryMiddleware(
        retryTestMetadata(new Retry(attempts: 2, delayMs: 100, maxDelayMs: 100)),
        sleep: static function (int $milliseconds) use (&$waits): void {
            $waits[] = $milliseconds;
        },
        randomInt: static function (int $min, int $max) use ($upper, &$ranges): int {
            $ranges[] = [$min, $max];
            return $upper ? $max : $min;
        },
    );

    $operation = Operation::create(new stdClass());
    expect($middleware->execute($operation, $handler))->toBe($operation)
        ->and($handler->attempts)->toBe(2)
        ->and($waits)->toBe([$expectedWait])
        ->and($ranges)->toBe([[-25, 25]]);
})->with([
    'upper endpoint capped' => [true, 100],
    'lower endpoint' => [false, 75],
]);

it('propagates the exhausted failure without waiting again', function (): void {
    $waits = [];
    $failure = new CqrsRetryableTestException('exhausted');
    $handler = new class ($failure) implements OperationHandlerInterface {
        public int $attempts = 0;
        public function __construct(private Throwable $failure)
        {
        }
        public function handle(OperationInterface $operation): OperationInterface
        {
            ++$this->attempts;
            throw $this->failure;
        }
    };
    $middleware = new RetryMiddleware(
        retryTestMetadata(new Retry(attempts: 2, delayMs: 7)),
        jitter: false,
        sleep: static function (int $milliseconds) use (&$waits): void {
            $waits[] = $milliseconds;
        },
    );

    expect(fn () => $middleware->execute(Operation::create(new stdClass()), $handler))->toThrow($failure);
    expect($handler->attempts)->toBe(2)->and($waits)->toBe([7]);
});

it('rejects non-finite retry multipliers', function (float $multiplier): void {
    expect(fn () => new Retry(multiplier: $multiplier))
        ->toThrow(InvalidArgumentException::class, 'finite');
})->with([NAN, INF, -INF]);

it('rejects invalid retryable exception declarations', function (array $exceptions): void {
    expect(fn () => new RetryMiddleware(retryTestMetadata(), $exceptions))
        ->toThrow(InvalidArgumentException::class, 'Throwable class or interface');
})->with([
    'non-throwable class' => [[stdClass::class]],
    'empty class' => [['']],
]);

it('rejects associative retryable exception declarations', function (): void {
    expect(fn () => new RetryMiddleware(
        retryTestMetadata(),
        ['exception' => RuntimeException::class],
    ))->toThrow(InvalidArgumentException::class, 'must be a list');
});

it('rejects delays that cannot be converted safely to microseconds', function (): void {
    expect(fn () => new Retry(maxDelayMs: PHP_INT_MAX))
        ->toThrow(InvalidArgumentException::class, 'too large');
});

it('propagates the original failure without retrying when metadata or eligibility excludes retry', function (string $scenario): void {
    $failure = in_array($scenario, ['not retryable', 'not configured'], true)
        ? new DomainException('permanent failure')
        : new CqrsRetryableTestException('transient failure');
    $operation = Operation::create(new stdClass(), ['trace' => 'original']);
    $handler = new class ($failure) implements OperationHandlerInterface {
        public array $operations = [];
        public function __construct(private readonly Throwable $failure) {}
        public function handle(OperationInterface $operation): OperationInterface
        {
            $this->operations[] = $operation;
            throw $this->failure;
        }
    };
    $waits = [];
    $metadata = match ($scenario) {
        'not marked' => retryTestMetadata(),
        'single attempt' => retryTestMetadata(new Retry(attempts: 1)),
        default => retryTestMetadata(new Retry(attempts: 3)),
    };
    $middleware = new RetryMiddleware(
        $metadata,
        retryableExceptions: $scenario === 'not configured' ? [InvalidArgumentException::class] : [],
        jitter: false,
        sleep: static function (int $delay) use (&$waits): void { $waits[] = $delay; },
    );
    try {
        $middleware->execute($operation, $handler);
        test()->fail('Expected the original failure.');
    } catch (RuntimeException|DomainException $caught) {
        expect($caught)->toBe($failure);
    }

    expect($handler->operations)->toBe([$operation])
        ->and($waits)->toBe([]);
})->with(['not marked', 'not retryable', 'not configured', 'single attempt']);

it('retries an explicitly configured throwable using the original operation each time', function (): void {
    $operation = Operation::create(new stdClass(), ['trace' => 'original']);
    $handler = new class implements OperationHandlerInterface {
        public array $operations = [];
        public function handle(OperationInterface $operation): OperationInterface
        {
            $this->operations[] = $operation;
            if (count($this->operations) === 1) {
                throw new DomainException('configured transient failure');
            }
            return $operation->withResult(new OperationResult(null));
        }
    };
    $waits = [];
    $middleware = new RetryMiddleware(
        retryTestMetadata(new Retry(attempts: 2, delayMs: 5)),
        [DomainException::class],
        jitter: false,
        sleep: static function (int $delay) use (&$waits): void { $waits[] = $delay; },
    );
    $result = $middleware->execute($operation, $handler);

    expect($handler->operations)->toBe([$operation, $operation])
        ->and($waits)->toBe([5])
        ->and($operation->result)->toBeNull()
        ->and($result->result)->toBeInstanceOf(OperationResult::class)
        ->and($result->result->value)->toBeNull()
        ->and($result->id)->toBe($operation->id)
        ->and($result->createdAt)->toBe($operation->createdAt)
        ->and($result->attributes)->toBe($operation->attributes);
});
