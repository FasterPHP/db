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

    public function __construct(?array $patterns = null)
    {
        $this->patterns = $patterns ?? self::DEFAULT_PATTERNS;
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
}
