<?php

declare(strict_types=1);

namespace Componenta\CQRS\Command\Middleware;

final class RetrySleepProbe
{
    /** @var list<int>|null */
    public static ?array $waits = null;
}

function usleep(int $microseconds): void
{
    if (RetrySleepProbe::$waits !== null) {
        RetrySleepProbe::$waits[] = $microseconds;
        return;
    }

    \usleep($microseconds);
}
