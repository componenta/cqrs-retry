<?php

declare(strict_types=1);

use Componenta\CQRS\Retry\Attribute\Retry;

it('exposes the documented retry defaults and accepts exact boundary values', function (): void {
    $default = new Retry();
    expect($default->attempts)->toBe(3)
        ->and($default->delayMs)->toBe(100)
        ->and($default->multiplier)->toBe(1.0)
        ->and($default->maxDelayMs)->toBe(10000);

    $minimum = new Retry(attempts: 1, delayMs: 0, multiplier: 1.0, maxDelayMs: 0);
    expect($minimum->attempts)->toBe(1)
        ->and($minimum->delayMs)->toBe(0)
        ->and($minimum->maxDelayMs)->toBe(0);

    $maximum = intdiv(PHP_INT_MAX, 1000);
    $boundary = new Retry(delayMs: $maximum, maxDelayMs: $maximum);
    expect($boundary->delayMs)->toBe($maximum)
        ->and($boundary->maxDelayMs)->toBe($maximum);
    expect(fn () => new Retry(maxDelayMs: $maximum + 1))
        ->toThrow(InvalidArgumentException::class, 'too large');
});

it('rejects invalid retry attempt and delay configuration', function (array $arguments, string $message): void {
    expect(fn () => new Retry(...$arguments))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'zero attempts' => [['attempts' => 0], 'Attempts must be at least 1'],
    'negative attempts' => [['attempts' => -1], 'Attempts must be at least 1'],
    'negative delay' => [['delayMs' => -1], 'Delay must be non-negative'],
    'multiplier below one' => [['multiplier' => 0.99], 'at least 1.0'],
    'max below initial delay' => [['delayMs' => 10, 'maxDelayMs' => 9], 'greater than or equal to delay'],
]);
