<?php

declare(strict_types=1);

namespace FasterPhp\Db\Config;

use FasterPhp\Db\DbException;

class ArrayProvider implements ProviderInterface
{
    protected array $configs;

    public function __construct(array $configs)
    {
        $this->configs = $configs;
    }

    public function getDsn(string $key): string
    {
        return $this->configs[$key]['dsn'] ?? throw new DbException("DSN not found for $key");
    }

    public function getUsername(string $key): ?string
    {
        return $this->configs[$key]['username'] ?? null;
    }

    public function getPassword(string $key): ?string
    {
        return $this->configs[$key]['password'] ?? null;
    }

    public function getOptions(string $key): ?array
    {
        return $this->configs[$key]['options'] ?? null;
    }
}
