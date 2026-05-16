<?php

declare(strict_types=1);

namespace FasterPhp\Db\Reconnect;

use Throwable;

interface StrategyInterface
{
    public function addPattern(string $pattern): self;
    public function shouldReconnect(Throwable $e): bool;
    public function getMaxAttempts(): int;
    public function getDelayMs(int $attempt): int;
}
