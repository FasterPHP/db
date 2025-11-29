<?php

declare(strict_types=1);

namespace FasterPhp\Db\Reconnect;

use PDOException;

class DefaultStrategy implements StrategyInterface
{
    public const DEFAULT_PATTERNS = [
        'server has gone away',
        'no connection to the server',
        'Lost connection',
        'is dead or not enabled',
        'MySQL server has gone away',
        'SSL connection has been closed unexpectedly',
        'Error writing data to the connection',
    ];

    protected array $patterns;
    protected int $maxAttempts;
    protected int $baseDelayMs;
    protected float $backoffMultiplier;

    public function __construct(
        ?array $patterns = null,
        int $maxAttempts = 1,
        int $baseDelayMs = 100,
        float $backoffMultiplier = 2.0
    ) {
        $this->patterns = $patterns ?? self::DEFAULT_PATTERNS;
        $this->maxAttempts = $maxAttempts;
        $this->baseDelayMs = $baseDelayMs;
        $this->backoffMultiplier = $backoffMultiplier;
    }

    public function getPatterns(): array
    {
        return $this->patterns;
    }

    public function addPattern(string $pattern): self
    {
        $this->patterns[] = $pattern;
        return $this;
    }

    public function shouldReconnect(PDOException $e): bool
    {
        $message = $e->getMessage();
        foreach ($this->patterns as $pattern) {
            if (false !== stripos($message, $pattern)) {
                return true;
            }
        }
        return false;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function getDelayMs(int $attempt): int
    {
        if ($attempt <= 1) {
            return 0;
        }
        return (int) ($this->baseDelayMs * pow($this->backoffMultiplier, $attempt - 2));
    }
}
