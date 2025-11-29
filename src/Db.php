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
        return $this->attemptWithReconnect(fn() => $this->getPdo()->beginTransaction());
    }

    public function commit(): bool
    {
        return $this->getPdo()->commit();
    }

    public function rollBack(): bool
    {
        return $this->getPdo()->rollBack();
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
            if ($this->reconnectStrategy->shouldReconnect($e)) {
                $this->setPdo(null);
                return $operation();
            }
            throw $e;
        }
    }
}
