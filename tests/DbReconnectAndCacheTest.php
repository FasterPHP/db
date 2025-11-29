<?php

declare(strict_types=1);

namespace FasterPhp\Db;

use PHPUnit\Framework\TestCase;
use PDO;

final class DbReconnectAndCacheTest extends TestCase
{
    private ?Db $db = null;

    protected function setUp(): void
    {
        $this->db = new Db(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASS'));
    }

    public function testStatementCaching(): void
    {
        $query = 'SELECT 1';
        $stmt1 = $this->db->prepare($query);
        $stmt2 = $this->db->prepare($query);
        $this->assertInstanceOf(DbStatement::class, $stmt1);
        $this->assertSame($stmt1, $stmt2, 'Prepared statement should be reused from cache');
    }

    public function testClearStatementCache(): void
    {
        $query = 'SELECT 1';
        $stmt1 = $this->db->prepare($query);
        $this->db->clearStatementCache();
        $stmt2 = $this->db->prepare($query);
        $this->assertNotSame($stmt1, $stmt2, 'After clearing cache, a new statement should be created');
    }

    public function testReconnectDuringPrepare(): void
    {
        $this->assertInstanceOf(Db::class, $this->db);
        $this->db->exec('SET SESSION wait_timeout = 1');
        sleep(2);
        $stmt = $this->db->prepare('SELECT 1');
        $this->assertInstanceOf(DbStatement::class, $stmt);
    }

    public function testReconnectDuringExecute(): void
    {
        $stmt = $this->db->prepare('SELECT 1 AS val');
        $connectionIdBefore = $this->getConnectionId($this->db);
        $this->db->exec('SET SESSION wait_timeout = 1');
        sleep(2);
        $stmt->execute();
        $this->assertSame(['val' => 1], $stmt->fetch(PDO::FETCH_ASSOC));
        $connectionIdAfter = $this->getConnectionId($this->db);
        $this->assertNotSame($connectionIdBefore, $connectionIdAfter);
    }

    public function testReconnectDuringExec(): void
    {
        // Use a fresh connection to avoid unbuffered query issues
        $db = new Db(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASS'));
        $connectionIdBefore = $this->getConnectionId($db);
        $db->exec('SET SESSION wait_timeout = 1');
        sleep(2);
        $result = $db->exec('DO 1');
        $this->assertSame(0, $result);
        $connectionIdAfter = $this->getConnectionId($db);
        $this->assertNotSame($connectionIdBefore, $connectionIdAfter);
    }

    private function getConnectionId(Db $db): int
    {
        $stmt = $db->query('SELECT CONNECTION_ID() AS id');
        return $stmt->fetch(PDO::FETCH_ASSOC)['id'];
    }
}
