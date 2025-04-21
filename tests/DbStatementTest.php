<?php

declare(strict_types=1);

namespace FasterPhp\Db;

use PHPUnit\Framework\TestCase;
use PDO;
use PDOException;

final class DbStatementTest extends TestCase
{
    private ?Db $db = null;

    protected function setUp(): void
    {
        $this->db = new Db(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASS'));
        $this->db->exec("DROP TABLE IF EXISTS `users`");
        $this->db->exec("
            CREATE TABLE `users` (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL DEFAULT ''
            ) ENGINE=InnoDB;
        ");
    }

    public function testBindColumn(): void
    {
        $this->db->exec("INSERT INTO users (name) VALUES ('Carol')");
        $stmt = $this->db->prepare('SELECT id, name FROM users WHERE name = :name');
        $stmt->execute([':name' => 'Carol']);
        $id = null;
        $name = null;
        $stmt->bindColumn('id', $id, PDO::PARAM_INT);
        $stmt->bindColumn('name', $name, PDO::PARAM_STR);
        $stmt->fetch(PDO::FETCH_BOUND);
        $this->assertNotNull($id);
        $this->assertSame('Carol', $name);
    }

    public function testBindParam(): void
    {
        $stmt = $this->db->prepare('INSERT INTO users (name) VALUES (:name)');
        $name = 'Bob';
        $stmt->bindParam(':name', $name);
        $this->assertTrue($stmt->execute());
    }

    public function testBindValueAndExecute(): void
    {
        $stmt = $this->db->prepare('INSERT INTO users (name) VALUES (:name)');
        $stmt->bindValue(':name', 'Alice');
        $this->assertTrue($stmt->execute());
    }

    public function testCloseCursor(): void
    {
        $stmt = $this->db->prepare('SELECT 1');
        $stmt->execute();
        $this->assertTrue($stmt->closeCursor());
    }

    public function testColumnCount(): void
    {
        $stmt = $this->db->prepare('SELECT id, name FROM users');
        $stmt->execute();
        $this->assertSame(2, $stmt->columnCount());
    }

    public function testDebugDumpParams(): void
    {
        $stmt = $this->db->prepare('SELECT :val');
        $stmt->bindValue(':val', 'debug');
        ob_start();
        $stmt->debugDumpParams();
        $output = ob_get_clean();
        $this->assertStringContainsString(':val', $output);
    }

    public function testErrorCodeAndErrorInfo(): void
    {
        $stmt = $this->db->prepare('SELECT * FROM nonexistent_table');
        try {
            $stmt->execute();
        } catch (PDOException $e) {
            // Expected exception due to nonexistent table
        }
        $errorCode = $stmt->errorCode();
        $errorInfo = $stmt->errorInfo();
        $this->assertNotSame('00000', $errorCode);
        $this->assertIsArray($errorInfo);
        $this->assertSame($errorCode, $errorInfo[0]);
    }

    public function testExecuteAndFetch(): void
    {
        $this->db->exec("INSERT INTO users (name) VALUES ('Dave')");
        $stmt = $this->db->prepare('SELECT * FROM users WHERE name = :name');
        $stmt->execute([':name' => 'Dave']);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($result);
        $this->assertSame('Dave', $result['name']);
    }

    public function testFetchAll(): void
    {
        $this->db->exec("INSERT INTO users (name) VALUES ('Eve'), ('Frank')");
        $stmt = $this->db->prepare('SELECT * FROM users');
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $rows);
        $this->assertSame('Eve', $rows[0]['name']);
    }

    public function testFetchColumn(): void
    {
        $this->db->exec("INSERT INTO users (name) VALUES ('Heidi')");
        $stmt = $this->db->prepare('SELECT name FROM users WHERE name = :name');
        $stmt->execute([':name' => 'Heidi']);
        $value = $stmt->fetchColumn();
        $this->assertSame('Heidi', $value);
    }

    public function testFetchObject(): void
    {
        $this->db->exec("INSERT INTO users (name) VALUES ('Frank')");
        $stmt = $this->db->prepare('SELECT * FROM users WHERE name = :name');
        $stmt->execute([':name' => 'Frank']);
        $obj = $stmt->fetchObject();
        $this->assertIsObject($obj);
        $this->assertSame('Frank', $obj->name);
    }

    public function testGetAttribute(): void
    {
        $stmt = $this->db->prepare('SELECT 1');
        try {
            $stmt->getAttribute(PDO::ATTR_CURSOR);
        } catch (PDOException $ex) {
            $this->assertStringContainsString('Driver does not support this function', $ex->getMessage());
        }
    }

    public function testGetColumnMeta(): void
    {
        $this->db->exec("INSERT INTO users (name) VALUES ('Grace')");
        $stmt = $this->db->prepare('SELECT id, name FROM users');
        $stmt->execute();
        $meta = $stmt->getColumnMeta(1);
        $this->assertIsArray($meta);
        $this->assertArrayHasKey('name', $meta);
        $this->assertSame('name', $meta['name']);
    }

    public function testGetIterator(): void
    {
        $this->db->exec("INSERT INTO users (name) VALUES ('Helen'), ('Ian')");
        $stmt = $this->db->prepare('SELECT * FROM users');
        $stmt->execute();
        $rows = [];
        foreach ($stmt->getIterator() as $row) {
            $rows[] = $row;
        }
        $this->assertCount(2, $rows);
    }

    public function testNextRowset(): void
    {
        $stmt = $this->db->prepare('SELECT 1; SELECT 2;');
        $stmt->execute();
        $this->assertTrue($stmt->nextRowset());
    }

    public function testRowCount(): void
    {
        $this->db->exec("INSERT INTO users (name) VALUES ('Grace')");
        $stmt = $this->db->prepare('DELETE FROM users WHERE name = :name');
        $stmt->execute([':name' => 'Grace']);
        $this->assertSame(1, $stmt->rowCount());
    }

    public function testSetAttribute(): void
    {
        $stmt = $this->db->prepare('SELECT 1');
        try {
            $stmt->setAttribute(PDO::ATTR_CURSOR, PDO::CURSOR_FWDONLY);
        } catch (PDOException $ex) {
            $this->assertStringContainsString('Driver does not support this function', $ex->getMessage());
        }
    }

    public function testSetFetchMode(): void
    {
        $this->db->exec("INSERT INTO users (name) VALUES ('Jack')");
        $stmt = $this->db->prepare('SELECT * FROM users');
        $stmt->execute();
        $stmt->setFetchMode(PDO::FETCH_ASSOC);
        $result = $stmt->fetch();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('name', $result);
    }
}
