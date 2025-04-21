<?php

declare(strict_types=1);

namespace FasterPhp\Db;

use FasterPhp\Db\Config\ProviderInterface as ConfigProvider;
use FasterPhp\Db\Reconnect;

class ConnectionManager
{
    protected ConfigProvider $configProvider;
    protected Reconnect\StrategyInterface $reconnectStrategy;
    private array $connections = [];

    public function __construct(
        ConfigProvider $configProvider,
        ?Reconnect\StrategyInterface $reconnectStrategy = null
    ) {
        $this->configProvider = $configProvider;
        $this->reconnectStrategy = $reconnectStrategy ?? new Reconnect\DefaultStrategy();
    }

    public function get(string $key): Db
    {
        return $this->connections[$key] ??= new Db(
            $this->configProvider->getDsn($key),
            $this->configProvider->getUsername($key),
            $this->configProvider->getPassword($key),
            $this->configProvider->getOptions($key),
            $this->reconnectStrategy
        );
    }

    public function reset(string $key): void
    {
        unset($this->connections[$key]);
    }

    public function all(): array
    {
        return $this->connections;
    }
}
