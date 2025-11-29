<?php

declare(strict_types=1);

namespace FasterPhp\Db;

use FasterPhp\Db\Reconnect\DefaultStrategy;
use PHPUnit\Framework\TestCase;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;

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

    public function testReconnectBlockedDuringTransaction(): void
    {
        $db = new Db(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASS'));
        $db->beginTransaction();
        $db->exec('SET SESSION wait_timeout = 1');
        sleep(2);

        $this->expectException(DbException::class);
        $this->expectExceptionMessage('Connection lost during transaction');
        $db->exec('SELECT 1');
    }

    public function testReconnectBlockedDuringTransactionViaStatement(): void
    {
        $db = new Db(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASS'));
        $stmt = $db->prepare('SELECT 1');
        $db->beginTransaction();
        $db->exec('SET SESSION wait_timeout = 1');
        sleep(2);

        $this->expectException(DbException::class);
        $this->expectExceptionMessage('Connection lost during transaction');
        $stmt->execute();
    }

    public function testSetPdoNullResetsTransactionFlag(): void
    {
        $db = new Db(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASS'));
        $db->beginTransaction();
        $this->assertTrue($db->inTransaction());

        $db->setPdo(null);

        // After setPdo(null), the flag should be reset
        // A new connection should not be in a transaction
        $this->assertFalse($db->inTransaction());
    }

    public function testAssertNotInTransactionPassesWhenNotInTransaction(): void
    {
        $db = new Db(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASS'));

        // Should not throw - we're not in a transaction
        $db->assertNotInTransaction(new PDOException('test'));
        $this->assertTrue(true); // If we get here, the test passed
    }

    public function testAssertNotInTransactionThrowsWhenFlagSet(): void
    {
        $db = new Db(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASS'));
        $db->beginTransaction();

        $this->expectException(DbException::class);
        $this->expectExceptionMessage('Connection lost during transaction');
        $db->assertNotInTransaction(new PDOException('test'));
    }

    public function testAssertNotInTransactionThrowsWhenPdoReportsInTransaction(): void
    {
        // Test the belt-and-braces check: PDO reports in transaction even though
        // our local flag is false. We need to inject a PDO that reports inTransaction=true
        // without going through our beginTransaction() which sets the flag.

        // Use anonymous class to inject a real PDO directly (bypassing setPdo's flag reset)
        // and expose the internal pdo property for verification
        $dsn = getenv('DB_DSN');
        $user = getenv('DB_USER');
        $pass = getenv('DB_PASS');

        $db = new class ($dsn, $user, $pass) extends Db {
            public function injectPdoWithTransaction(string $dsn, ?string $user, ?string $pass): void
            {
                // Create a real PDO and start a transaction on it directly
                $this->pdo = new \PDO($dsn, $user, $pass);
                $this->pdo->beginTransaction();
            }

            public function getInternalPdo(): ?\PDO
            {
                return $this->pdo;
            }
        };
        $db->injectPdoWithTransaction($dsn, $user, $pass);

        // Verify PDO is set and in transaction
        $this->assertNotNull($db->getInternalPdo());
        $this->assertTrue($db->getInternalPdo()->inTransaction());

        // Our flag is false, but PDO reports in transaction
        try {
            $db->assertNotInTransaction(new PDOException('test'));
            $this->fail('Expected DbException was not thrown');
        } catch (DbException $e) {
            $this->assertSame('Connection lost during transaction', $e->getMessage());
        }
    }

    public function testLoggerIsCalledOnReconnect(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Database connection lost and reconnected',
                $this->callback(function ($context) {
                    return isset($context['dsn']) && isset($context['exception']);
                })
            );

        $db = new Db(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASS'));
        $db->setLogger($logger);
        $db->exec('SET SESSION wait_timeout = 1');
        sleep(2);
        $db->exec('SELECT 1');
    }

    public function testLoggerIsCalledOnReconnectViaStatement(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Database connection lost and reconnected',
                $this->callback(function ($context) {
                    return isset($context['dsn']) && isset($context['exception']);
                })
            );

        $db = new Db(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASS'));
        $db->setLogger($logger);
        $stmt = $db->prepare('SELECT 1');
        $db->exec('SET SESSION wait_timeout = 1');
        sleep(2);
        $stmt->execute();
    }

    public function testLogReconnectWithNoLogger(): void
    {
        $db = new Db(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASS'));
        // Should not throw when no logger is set
        $db->logReconnect(new PDOException('test'));
        $this->assertTrue(true);
    }

    private function getConnectionId(Db $db): int
    {
        $stmt = $db->query('SELECT CONNECTION_ID() AS id');
        return $stmt->fetch(PDO::FETCH_ASSOC)['id'];
    }
}
