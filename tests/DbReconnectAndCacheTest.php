<?php

declare(strict_types=1);

namespace FasterPhp\Db;

use FasterPhp\Db\Reconnect\DefaultStrategy;
use PHPUnit\Framework\TestCase;
use PDO;
use PDOException;

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

    public function testReconnectWithMultipleAttempts(): void
    {
        $strategy = new DefaultStrategy(null, 3, 10, 2.0);
        $db = new Db(
            getenv('DB_DSN'),
            getenv('DB_USER'),
            getenv('DB_PASS'),
            null,
            $strategy
        );
        $connectionIdBefore = $this->getConnectionId($db);
        $db->exec('SET SESSION wait_timeout = 1');
        sleep(2);
        $result = $db->exec('DO 1');
        $this->assertSame(0, $result);
        $connectionIdAfter = $this->getConnectionId($db);
        $this->assertNotSame($connectionIdBefore, $connectionIdAfter);
    }

    public function testReconnectFailsAfterMaxAttempts(): void
    {
        $strategy = new DefaultStrategy(null, 2, 10, 2.0);
        $db = new class (
            'mysql:host=invalid_host_that_does_not_exist;dbname=testdb',
            'root',
            '',
            null,
            $strategy
        ) extends Db {
            private int $attemptCount = 0;

            public function getPdo(): \PDO
            {
                $this->attemptCount++;
                throw new PDOException('MySQL server has gone away');
            }

            public function getAttemptCount(): int
            {
                return $this->attemptCount;
            }
        };

        try {
            $db->exec('SELECT 1');
            $this->fail('Expected PDOException to be thrown');
        } catch (PDOException $e) {
            $this->assertStringContainsString('MySQL server has gone away', $e->getMessage());
            // Initial attempt + 2 retry attempts = 3 total
            $this->assertSame(3, $db->getAttemptCount());
        }
    }

    public function testReconnectThrowsNonReconnectableException(): void
    {
        $strategy = new DefaultStrategy(null, 3, 10, 2.0);
        $db = new class (
            'mysql:host=invalid_host;dbname=testdb',
            'root',
            '',
            null,
            $strategy
        ) extends Db {
            private int $attemptCount = 0;

            public function getPdo(): \PDO
            {
                $this->attemptCount++;
                if ($this->attemptCount === 1) {
                    throw new PDOException('MySQL server has gone away');
                }
                throw new PDOException('Syntax error');
            }

            public function getAttemptCount(): int
            {
                return $this->attemptCount;
            }
        };

        try {
            $db->exec('SELECT 1');
            $this->fail('Expected PDOException to be thrown');
        } catch (PDOException $e) {
            $this->assertStringContainsString('Syntax error', $e->getMessage());
            // Should stop after first retry since it's not a reconnectable error
            $this->assertSame(2, $db->getAttemptCount());
        }
    }

    private function getConnectionId(Db $db): int
    {
        $stmt = $db->query('SELECT CONNECTION_ID() AS id');
        return $stmt->fetch(PDO::FETCH_ASSOC)['id'];
    }
}
