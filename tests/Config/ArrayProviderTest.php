<?php

declare(strict_types=1);

namespace FasterPhp\Db\Config;

use FasterPhp\Db\DbException;
use PHPUnit\Framework\TestCase;
use PDO;

final class ArrayProviderTest extends TestCase
{
    private ArrayProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new ArrayProvider([
            'main' => [
                'dsn' => 'mysql:host=localhost;dbname=test',
                'username' => 'root',
                'password' => 'secret',
                'options' => [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ],
            ],
        ]);
    }

    public function testGetDsn(): void
    {
        $this->assertSame('mysql:host=localhost;dbname=test', $this->provider->getDsn('main'));
    }

    public function testGetUsername(): void
    {
        $this->assertSame('root', $this->provider->getUsername('main'));
    }

    public function testGetPassword(): void
    {
        $this->assertSame('secret', $this->provider->getPassword('main'));
    }

    public function testGetOptions(): void
    {
        $this->assertSame([
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ], $this->provider->getOptions('main'));
    }

    public function testMissingKey(): void
    {
        $this->expectException(DbException::class);
        $this->expectExceptionMessage('DSN not found for nonexistent');
        $this->provider->getDsn('nonexistent');
    }

    public function testNullFields(): void
    {
        $provider = new ArrayProvider([
            'minimal' => [
                'dsn' => 'mysql:host=localhost;dbname=test',
            ],
        ]);

        $this->assertNull($provider->getUsername('minimal'));
        $this->assertNull($provider->getPassword('minimal'));
        $this->assertNull($provider->getOptions('minimal'));
    }
}
