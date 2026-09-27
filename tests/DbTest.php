<?php

declare(strict_types=1);

namespace FasterPhp\Db;

use PHPUnit\Framework\TestCase;
use PDO;
use PDOException;

final class DbTest extends TestCase
{
    private ?Db $db = null;

    protected function setUp(): void
    {
        $this->db = new Db(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASS'));
        $this->db->exec("DROP TABLE IF EXISTS `test`");
        $this->db->exec("
            CREATE TABLE `test` (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL DEFAULT ''
            ) ENGINE=InnoDB;
        ");
    }

    public function testLazyConnection(): void
    {
        $this->assertInstanceOf(Db::class, $this->db);
        $this->assertInstanceOf(PDO::class, $this->db);
        $this->assertInstanceOf(PDO::class, $this->db->getPdo());
    }

    public function testQuote(): void
    {
        $quoted = $this->db->quote("O'Reilly");
        $this->assertSame("'O\\'Reilly'", $quoted);
    }

    public function testInsertAndQuery(): void
    {
        $this->db->exec("INSERT INTO test (name) VALUES ('Alice')");
        $stmt = $this->db->query("SELECT name FROM test WHERE id = 1");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['name' => 'Alice'], $result);
    }

    public function testPrepareAndExecute(): void
    {
        $stmt = $this->db->prepare("INSERT INTO test (name) VALUES (:name)");
        $stmt->execute([':name' => 'Bob']);

        $stmt = $this->db->prepare("SELECT name FROM test WHERE name = :name");
        $stmt->execute([':name' => 'Bob']);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['name' => 'Bob'], $result);
    }

    public function testTransactions(): void
    {
        $this->assertFalse($this->db->inTransaction());
        $this->db->beginTransaction();
        $this->assertTrue($this->db->inTransaction());
        $this->db->commit();
        $this->assertFalse($this->db->inTransaction());
    }

    public function testRollback(): void
    {
        $this->db->beginTransaction();
        $this->db->exec("INSERT INTO test (name) VALUES ('Charlie')");
        $this->db->rollBack();
        $this->assertFalse($this->db->inTransaction(), 'Transaction not rolled back');

        $stmt = $this->db->query("SELECT COUNT(*) FROM test");
        $count = $stmt->fetchColumn();
        $this->assertSame("0", (string)$count);
    }

    public function testLastInsertId(): void
    {
        $this->db->exec("INSERT INTO test (name) VALUES ('Charlie')");
        $this->assertSame('1', $this->db->lastInsertId());
    }

    public function testErrorCode(): void
    {
        try {
            $this->db->exec("INSERT INTO notexists (name) VALUES ('blah')");
        } catch (PDOException) {
        }
        $this->assertSame('42S02', $this->db->errorCode());
    }

    public function testErrorInfo(): void
    {
        try {
            $this->db->exec("INSERT INTO notexists (name) VALUES ('blah')");
        } catch (PDOException) {
        }
        $this->assertSame(['42S02', 1146, "Table 'db_test.notexists' doesn't exist"], $this->db->errorInfo());
    }

    public function testSetGetAttribute(): void
    {
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $this->db->getAttribute(PDO::ATTR_ERRMODE));
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
        $this->assertSame(PDO::ERRMODE_WARNING, $this->db->getAttribute(PDO::ATTR_ERRMODE));
    }

    public function testGetAvailableDrivers(): void
    {
        $drivers = $this->db->getAvailableDrivers();
        $this->assertIsArray($drivers);
        $this->assertContains('mysql', $drivers);
    }

    public function testPrepareReturnsFalseWhenPdoReturnsFalse(): void
    {
        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->method('prepare')->willReturn(false);

        $db = new Db(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASS'));
        $db->setPdo($mockPdo);

        $result = $db->prepare('SELECT 1');
        $this->assertFalse($result);
    }

    public function testQueryReturnsFalseWhenPdoReturnsFalse(): void
    {
        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->method('query')->willReturn(false);

        $db = new Db(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASS'));
        $db->setPdo($mockPdo);

        $result = $db->query('SELECT 1');
        $this->assertFalse($result);
    }

    public function testTransactionCommitsWork(): void
    {
        $this->db->transaction(function () {
            $this->db->exec("INSERT INTO test (name) VALUES ('Alice')");
        });

        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(1, $this->countRows());
    }

    public function testTransactionReturnsWorkResult(): void
    {
        $object = new \stdClass();

        $this->assertNull($this->db->transaction(fn() => null));
        $this->assertFalse($this->db->transaction(fn() => false));
        $this->assertSame($object, $this->db->transaction(fn() => $object));
    }

    public function testTransactionRollsBackAndRethrowsException(): void
    {
        $exception = new \RuntimeException('work failed');

        try {
            $this->db->transaction(function () use ($exception) {
                $this->db->exec("INSERT INTO test (name) VALUES ('Alice')");
                throw $exception;
            });
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame($exception, $e);
        }

        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(0, $this->countRows());
    }

    public function testTransactionRollsBackAndRethrowsError(): void
    {
        $error = new \TypeError('bad type');

        try {
            $this->db->transaction(function () use ($error) {
                $this->db->exec("INSERT INTO test (name) VALUES ('Alice')");
                throw $error;
            });
            $this->fail('Expected error was not thrown');
        } catch (\TypeError $e) {
            $this->assertSame($error, $e);
        }

        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(0, $this->countRows());
    }

    public function testTransactionThrowsWhenCommitReturnsFalse(): void
    {
        $db = new class (getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASS')) extends Db {
            public function commit(): bool
            {
                return false;
            }
        };

        try {
            $db->transaction(function () use ($db) {
                $db->exec("INSERT INTO test (name) VALUES ('Alice')");
            });
            $this->fail('Expected DbException was not thrown');
        } catch (DbException $e) {
            $this->assertSame('Transaction commit failed', $e->getMessage());
        }

        $this->assertFalse($db->inTransaction());
        $this->assertSame(0, $this->countRows());
    }

    public function testTransactionRethrowsOriginalWhenRollbackFails(): void
    {
        $db = new class (getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASS')) extends Db {
            public function rollBack(): bool
            {
                throw new \RuntimeException('rollback failed');
            }
        };
        $exception = new \LogicException('work failed');
        $pdoBefore = $db->getPdo();

        try {
            $db->transaction(function () use ($db, $exception) {
                $db->exec("INSERT INTO test (name) VALUES ('Alice')");
                throw $exception;
            });
            $this->fail('Expected exception was not thrown');
        } catch (\LogicException $e) {
            $this->assertSame($exception, $e);
        }

        // The connection is discarded, which ends the transaction server-side
        $this->assertNotSame($pdoBefore, $db->getPdo());
        $this->assertFalse($db->inTransaction());
        unset($pdoBefore);
        $this->assertSame(0, $this->countRows());
    }

    public function testNestedTransactionCommitsOnceViaOuterCall(): void
    {
        $this->db->transaction(function () {
            $this->db->exec("INSERT INTO test (name) VALUES ('Outer')");
            $this->db->transaction(function () {
                $this->db->exec("INSERT INTO test (name) VALUES ('Inner')");
            });
            $this->assertTrue($this->db->inTransaction(), 'Inner call must not commit');
        });

        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(2, $this->countRows());
    }

    public function testNestedTransactionFailureIsRolledBackByOuterCall(): void
    {
        $exception = new \RuntimeException('inner failed');

        try {
            $this->db->transaction(function () use ($exception) {
                $this->db->exec("INSERT INTO test (name) VALUES ('Outer')");
                $this->db->transaction(function () use ($exception) {
                    $this->db->exec("INSERT INTO test (name) VALUES ('Inner')");
                    throw $exception;
                });
            });
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame($exception, $e);
        }

        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(0, $this->countRows());
    }

    public function testTransactionJoinsManualTransaction(): void
    {
        $this->db->beginTransaction();
        $exception = new \RuntimeException('work failed');

        try {
            $this->db->transaction(function () use ($exception) {
                $this->db->exec("INSERT INTO test (name) VALUES ('Alice')");
                throw $exception;
            });
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame($exception, $e);
        }

        $this->assertTrue($this->db->inTransaction(), 'Joined call must not roll back');
        $this->db->rollBack();
        $this->assertSame(0, $this->countRows());
    }

    public function testTransactionJoinsRawSqlTransaction(): void
    {
        $this->db->exec('START TRANSACTION');

        $this->db->transaction(function () {
            $this->db->exec("INSERT INTO test (name) VALUES ('Alice')");
        });

        $this->assertTrue($this->db->inTransaction(), 'Joined call must not commit');
        $this->db->exec('ROLLBACK');
        $this->assertSame(0, $this->countRows());
    }

    private function countRows(): int
    {
        // Separate connection, so only committed rows are counted
        $db = new Db(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASS'));
        return (int)$db->query('SELECT COUNT(*) FROM test')->fetchColumn();
    }
}
