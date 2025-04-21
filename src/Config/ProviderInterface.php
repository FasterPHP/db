<?php

declare(strict_types=1);

namespace FasterPhp\Db\Config;

interface ProviderInterface
{
    public function getDsn(string $key): string;
    public function getUsername(string $key): ?string;
    public function getPassword(string $key): ?string;
    public function getOptions(string $key): ?array;
}
