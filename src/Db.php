<?php

declare(strict_types=1);

namespace FasterPhp\Db;

use FasterPhp\Db\Reconnect;
use PDO;
use PDOException;

class Db extends PDO
{
    protected string $dsn;
    protected ?string $username;
    protected ?string $password;
    protected ?array $options;
    protected Reconnect\StrategyInterface $reconnectStrategy;
    protected ?PDO $pdo = null;
    private array $statementCache = [];
    private bool $inTransactionFlag = false;

    public function __construct(
        string $dsn,
        ?string $username = null,
        #[\SensitiveParameter] ?string $password = null,
        ?array $options = null,
        ?Reconnect\StrategyInterface $reconnectStrategy = null
    ) {
        $this->dsn = $dsn;
        $this->username = $username;
        $this->password = $password;
        $this->options = $options;
        $this->reconnectStrategy = $reconnectStrategy ?? new Reconnect\DefaultStrategy();
    }

    public function getReconnectStrategy(): Reconnect\StrategyInterface
    {
        return $this->reconnectStrategy;
    }

    public function setPdo(?PDO $pdo): void
    {
        $this->pdo = $pdo;
        if ($pdo === null) {
            $this->inTransactionFlag = false;
        }
    }

    public function getPdo(): PDO
    {
        return $this->pdo ??= new PDO($this->dsn, $this->username, $this->password, $this->options);
    }

    public function clearStatementCache(): void
    {
        $this->statementCache = [];
    }

    public function prepare(string $query, array $options = [], DbStatement $dbStatement = null): DbStatement|false
    {
        $hash = md5($query . '|' . serialize($options));

        if (empty($dbStatement) && isset($this->statementCache[$hash])) {
            return $this->statementCache[$hash];
        }

        $stmt = $this->attemptWithReconnect(function () use ($query) {
            return $this->getPdo()->prepare($query);
        });

        if (false === $stmt) {
            return false;
        }

        if (empty($dbStatement)) {
            $dbStatement = new DbStatement($this, $stmt, $query, $options);
        } else {
            $dbStatement->setPdoStatement($stmt);
        }

        return $this->statementCache[$hash] = $dbStatement;
    }

    public function query(string $query, ?int $fetchMode = null, ...$fetchModeArgs): DbStatement|false
    {
        $stmt = $this->attemptWithReconnect(function () use ($query, $fetchMode, $fetchModeArgs) {
            return $this->getPdo()->query($query, $fetchMode, ...$fetchModeArgs);
        });

        if (false === $stmt) {
            return false;
        }

        return new DbStatement($this, $stmt, $query, []);
    }

    public function exec(string $statement): int|false
    {
        return $this->attemptWithReconnect(fn() => $this->getPdo()->exec($statement));
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->getPdo()->lastInsertId($name);
    }

    public function beginTransaction(): bool
    {
        $result = $this->attemptWithReconnect(fn() => $this->getPdo()->beginTransaction());
        if ($result) {
            $this->inTransactionFlag = true;
        }
        return $result;
    }

    public function commit(): bool
    {
        $result = $this->getPdo()->commit();
        if ($result) {
            $this->inTransactionFlag = false;
        }
        return $result;
    }

    public function rollBack(): bool
    {
        $result = $this->getPdo()->rollBack();
        if ($result) {
            $this->inTransactionFlag = false;
        }
        return $result;
    }

    public function inTransaction(): bool
    {
        return $this->getPdo()->inTransaction();
    }

    public function errorCode(): ?string
    {
        return $this->getPdo()->errorCode();
    }

    public function errorInfo(): array
    {
        return $this->getPdo()->errorInfo();
    }

    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        return $this->getPdo()->quote($string, $type);
    }

    public function getAttribute(int $attribute): mixed
    {
        return $this->getPdo()->getAttribute($attribute);
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        return $this->getPdo()->setAttribute($attribute, $value);
    }

    protected function attemptWithReconnect(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (PDOException $e) {
            if (!$this->reconnectStrategy->shouldReconnect($e)) {
                throw $e;
            }

            $this->assertNotInTransaction($e);

            $maxAttempts = $this->reconnectStrategy->getMaxAttempts();
            $lastException = $e;

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $delayMs = $this->reconnectStrategy->getDelayMs($attempt);
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }

                $this->setPdo(null);

                try {
                    return $operation();
                } catch (PDOException $retryException) {
                    $lastException = $retryException;
                    if (!$this->reconnectStrategy->shouldReconnect($retryException)) {
                        throw $retryException;
                    }
                }
            }

            throw $lastException;
        }
    }

    public function assertNotInTransaction(PDOException $cause): void
    {
        // Check local flag first (reliable even if connection is dead)
        if ($this->inTransactionFlag) {
            throw new DbException('Connection lost during transaction', 0, $cause);
        }

        // Belt-and-braces: also check PDO's state if connection is still alive
        try {
            if ($this->pdo?->inTransaction()) {
                throw new DbException('Connection lost during transaction', 0, $cause);
            }
        } catch (PDOException) {
            // Connection is dead, rely on local flag only (already checked above)
        }
    }
}
