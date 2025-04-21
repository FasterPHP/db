<?php

declare(strict_types=1);

namespace FasterPhp\Db;

use FasterPhp\Db\Config\ArrayProvider;
use PHPUnit\Framework\TestCase;
use PDO;

final class ConnectionManagerTest extends TestCase
{
    private ConnectionManager $manager;

    protected function setUp(): void
    {
        $config = [
            'test' => [
                'dsn' => getenv('DB_DSN'),
                'username' => getenv('DB_USER'),
                'password' => getenv('DB_PASS'),
                'options' => [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ],
            ],
        ];
        $provider = new ArrayProvider($config);
        $this->manager = new ConnectionManager($provider);
    }

    public function testReturnsDbInstance(): void
    {
        $db = $this->manager->get('test');
        $this->assertInstanceOf(Db::class, $db);
    }

    public function testSameInstanceReturned(): void
    {
        $db1 = $this->manager->get('test');
        $db2 = $this->manager->get('test');
        $this->assertSame($db1, $db2);
    }

    public function testResetClearsInstance(): void
    {
        $db1 = $this->manager->get('test');
        $this->manager->reset('test');
        $db2 = $this->manager->get('test');

        $this->assertNotSame($db1, $db2);
    }

    public function testAllReturnsActiveConnections(): void
    {
        $this->manager->get('test');
        $all = $this->manager->all();

        $this->assertArrayHasKey('test', $all);
        $this->assertInstanceOf(Db::class, $all['test']);
    }
}
