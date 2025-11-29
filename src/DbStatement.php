<?php

declare(strict_types=1);

namespace FasterPhp\Db;

use Iterator;
use PDOException;
use PDOStatement;
use PDO;

class DbStatement extends PDOStatement
{
    protected Db $db;
    protected PDOStatement $pdoStatement;
    protected string $sql;
    protected array $options;

    public function __construct(Db $db, PDOStatement $pdoStatement, string $sql, array $options = [])
    {
        $this->db = $db;
        $this->pdoStatement = $pdoStatement;
        $this->sql = $sql;
        $this->options = $options;
    }

    public function setPdoStatement(PDOStatement $pdoStatement): void
    {
        $this->pdoStatement = $pdoStatement;
    }

    public function bindColumn(
        string|int $column,
        mixed &$var,
        int $type = PDO::PARAM_STR,
        int $maxLength = 0,
        mixed $driverOptions = null
    ): bool {
        return $this->pdoStatement->bindColumn($column, $var, $type, $maxLength, $driverOptions);
    }

    public function bindParam(
        string|int $param,
        mixed &$var,
        int $type = PDO::PARAM_STR,
        int $maxLength = 0,
        mixed $driverOptions = null
    ): bool {
        return $this->pdoStatement->bindParam($param, $var, $type, $maxLength, $driverOptions);
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        return $this->pdoStatement->bindValue($param, $value, $type);
    }

    public function closeCursor(): bool
    {
        return $this->pdoStatement->closeCursor();
    }

    public function columnCount(): int
    {
        return $this->pdoStatement->columnCount();
    }

    public function debugDumpParams(): ?bool
    {
        return $this->pdoStatement->debugDumpParams();
    }

    public function errorCode(): ?string
    {
        return $this->pdoStatement->errorCode();
    }

    public function errorInfo(): array
    {
        return $this->pdoStatement->errorInfo();
    }

    public function execute(?array $params = null): bool
    {
        try {
            return $this->pdoStatement->execute($params);
        } catch (PDOException $e) {
            if ($this->db->getReconnectStrategy()->shouldReconnect($e)) {
                $this->db->setPdo(null);
                $this->db->prepare($this->sql, $this->options, $this);
                return $this->pdoStatement->execute($params);
            }
            throw $e;
        }
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        return $this->pdoStatement->fetch($mode, $cursorOrientation, $cursorOffset);
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, ...$params): array
    {
        return $this->pdoStatement->fetchAll($mode, ...$params);
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->pdoStatement->fetchColumn($column);
    }

    public function fetchObject(?string $class = 'stdClass', array $constructorArgs = []): object|false
    {
        return $this->pdoStatement->fetchObject($class, $constructorArgs);
    }

    public function getAttribute(int $name): mixed
    {
        return $this->pdoStatement->getAttribute($name);
    }

    public function getColumnMeta(int $column): array|false
    {
        return $this->pdoStatement->getColumnMeta($column);
    }

    public function getIterator(): Iterator
    {
        return $this->pdoStatement->getIterator();
    }

    public function nextRowset(): bool
    {
        return $this->pdoStatement->nextRowset();
    }

    public function rowCount(): int
    {
        return $this->pdoStatement->rowCount();
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        return $this->pdoStatement->setAttribute($attribute, $value);
    }

    public function setFetchMode(int $mode = PDO::FETCH_DEFAULT, ...$params): bool
    {
        return $this->pdoStatement->setFetchMode($mode, ...$params);
    }
}
