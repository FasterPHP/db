<?php
/**
 * Tests for Db class.
 */
namespace FasterPhp\Db;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Db class.
 */
class DbTest extends TestCase
{
	/**
	 * Setup before every test.
	 *
	 * @return void
	 */
	public function setUp(): void
	{
		ConnectionManager::clearConnectionPool();
		Db::clearStatementCache();
	}

	/**
	 * Test with unknown connection name.
	 *
	 * @return void
	 */
	public function testUnknownConnection(): void
	{
		$this->expectException(\FasterPhp\Db\Exception::class);
		$this->expectExceptionMessage("Database connection name 'foo' not found");

		Db::newDb('foo')->query('bar');
	}

	/**
	 * Test that new instance returns new connection when existing connection still in use.
	 *
	 * @return void
	 */
	public function testPooledInUse(): void
	{
		$dbA = Db::newDb('testdb');
		$connectionIdA = $this->_getConnectionId($dbA);

		// Create another instance which should NOT re-use the previous connection.
		$dbB = Db::newDb('testdb');
		$connectionIdB = $this->_getConnectionId($dbB);
		$this->assertNotSame($connectionIdA, $connectionIdB);
	}

	/**
	 * Test that new instance returns same connection when connection no longer in use.
	 *
	 * @return void
	 */
	public function testPooled(): void
	{
		$dbA = Db::newDb('testdb');
		$connectionIdA = $this->_getConnectionId($dbA);

		// Destroy the adapter to release its connection back into the pool.
		unset($dbA);

		// Create another instance which should re-use the previous connection.
		$dbB = Db::newDb('testdb');
		$connectionIdB = $this->_getConnectionId($dbB);
		$this->assertSame($connectionIdA, $connectionIdB);
	}

	/**
	 * Test that new instance returns new connection when pooling enabled, but buffered query not read.
	 *
	 * @return void
	 */
	public function testPooledBuffered(): void
	{
		$dbA = Db::newDb('testdb');
		$connectionIdA = $this->_getConnectionId($dbA);

		// Run exec query and don't read results, so it can't be re-used
		$dbA->exec('SHOW WARNINGS');
		unset($dbA);

		// Create another instance which should NOT re-use the previous connection.
		$dbB = Db::newDb('testdb');
		$connectionIdB = $this->_getConnectionId($dbB);
		$this->assertNotSame($connectionIdA, $connectionIdB);
	}

	/**
	 * Test that when multiple connections are used at the same time, they are later re-used.
	 *
	 * @return void
	 */
	public function testPooledConnectionsAreReUsed(): void
	{
		// Get first instance/connection
		$dbA = Db::newDb('testdb');
		$connectionIdA = $this->_getConnectionId($dbA);

		// Create buffered result so first connection can't be reused
		$dbA->exec('SHOW WARNINGS');
		unset($dbA);

		// Get second instance and confirm it's NOT the same connection
		$dbB = Db::newDb('testdb');
		$connectionIdB = $this->_getConnectionId($dbB);
		$this->assertNotSame($connectionIdA, $connectionIdB);

		// Release second instance back into pool
		unset($dbB);

		// Create third instance and confirm it's the same as the second
		$dbC = Db::newDb('testdb');
		$connectionIdC = $this->_getConnectionId($dbC);
		$this->assertSame($connectionIdB, $connectionIdC);

		// Create buffered result so third connection can't be reused
		$dbC->exec('SHOW WARNINGS');

		// Create final connection and confirm it's new
		$dbD = Db::newDb('testdb');
		$connectionIdD = $this->_getConnectionId($dbD);
		$this->assertNotSame($connectionIdA, $connectionIdD);
		$this->assertNotSame($connectionIdB, $connectionIdD);
		$this->assertNotSame($connectionIdC, $connectionIdD);
	}

	/**
	 * Test that new instance returns new connection when it times out.
	 *
	 * @return void
	 */
	public function testAutoReconnectTimeout(): void
	{
		$dbA = Db::newDb('testdb');
		$connectionIdA = $this->_getConnectionId($dbA);

		// Force connection to timeout
		$dbA->exec('SET wait_timeout = 1');
		sleep(2);
		unset($dbA);

		// Create another instance which should NOT re-use the previous connection.
		$dbB = Db::newDb('testdb');
		$connectionIdB = $this->_getConnectionId($dbB);
		$this->assertNotSame($connectionIdA, $connectionIdB);
	}

	/**
	 * Test that new instance returns new connection when it times out and exceptions not used.
	 *
	 * @return void
	 */
	public function testAutoReconnectTimeoutError(): void
	{
		$dbA = Db::newDb('testdb');
		$dbA->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_WARNING);
		$connectionIdA = $this->_getConnectionId($dbA);

		// Force connection to timeout
		$dbA->exec('SET wait_timeout = 1');
		sleep(2);
		unset($dbA);

		// Create another instance which should NOT re-use the previous connection.
		$dbB = Db::newDb('testdb');
		$dbB->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_WARNING);
		$connectionIdB = $this->_getConnectionId($dbB);
		$this->assertNotSame($connectionIdA, $connectionIdB);
	}

	/**
	 * Test auto-reconnect happens when pool contains only stale connections and auto-reconnect enabled.
	 *
	 * @return void
	 */
	public function testPooledAutoReconnectStaleCache(): void
	{
		$dbA = Db::newDb('testdb');
		$connectionIdA = $this->_getConnectionId($dbA);
		$dbA->exec('SET wait_timeout = 1');

		$dbB = Db::newDb('testdb');
		$connectionIdB = $this->_getConnectionId($dbB);
		$dbB->exec('SET wait_timeout = 1');

		unset($dbA);
		unset($dbB);

		sleep(2);

		// Create another instance which should re-use the previous connection.
		$dbC = Db::newDb('testdb');
		$connectionIdC = $this->_getConnectionId($dbC);

		$this->assertNotEquals($connectionIdA, $connectionIdB);
		$this->assertNotEquals($connectionIdA, $connectionIdC);
		$this->assertNotEquals($connectionIdB, $connectionIdC);
	}

	/**
	 * Test prepared statement cache.
	 *
	 * @return void
	 */
	public function testPreparedStatementCache(): void
	{
		$sql = 'SELECT 1';

		$mockStatement = $this->getMockBuilder(PDOStatement::class)
			->disableOriginalConstructor()
			->getMock();

		$mockPdo = $this->getMockBuilder(PDO::class)
			->disableOriginalConstructor()
			->setMethods(['prepare'])
			->getMock();
		$mockPdo->expects($this->once()) // Note: Should only be called once
			->method('prepare')
			->with($sql)
			->willReturn($mockStatement);

		$db = Db::newDb('testdb');
		$db->setPdo($mockPdo);

		$db->prepare($sql);
		$this->assertInstanceOf(Statement::class, $db->prepare($sql));
	}

	/**
	 * Test reconnect in prepare method when emulation disabled.
	 *
	 * @return void
	 */
	public function testPrepareReconnect(): void
	{
		ConnectionManager::clearConnectionPool();

		$db = Db::newDb('testdb');
		$db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
		$db->exec('SET wait_timeout = 1');

		sleep(2);

		$statement = $db->prepare('SELECT 1');

		$this->assertInstanceOf(Statement::class, $statement);
	}

	/**
	 * Test reconnect in execute method of prepared statement.
	 *
	 * @return void
	 */
	public function testPrepareExecuteReconnect(): void
	{
		$db = Db::newDb('testdb');
		$connectionIdA = $this->_getConnectionId($db);

		$statement = $db->prepare('SELECT 1 AS value');

		// Force connection to timeout
		$db->exec('SET wait_timeout = 1');
		sleep(2);

		$statement->execute();
		$this->assertEquals(['value' => 1], $statement->fetch());

		$connectionIdB = $this->_getConnectionId($db);
		$this->assertNotEquals($connectionIdA, $connectionIdB);
	}

	/**
	 * Test quote string.
	 *
	 * @return void
	 */
	public function testQuoteString(): void
	{
		$input = "test'text";
		$expected = "'test\\'text'";

		$db = Db::newDb('testdb');

		$this->assertSame($expected, $db->quote($input));
	}

	/**
	 * Test quote integer.
	 *
	 * @return void
	 */
	public function testQuoteInteger(): void
	{
		$input = 123;
		$expected = "'123'";

		$db = Db::newDb('testdb');

		$this->assertSame($expected, $db->quote($input));
	}

	/**
	 * Test quote array of strings.
	 *
	 * @return void
	 */
	public function testQuoteStringArray()
	{
		$input = ["test'text1", "test'text2"];
		$expected = "'test\\'text1', 'test\\'text2'";

		$db = Db::newDb('testdb');

		$this->assertSame($expected, $db->quote($input));
	}

	/**
	 * Test quote array of integers.
	 *
	 * @return void
	 */
	public function testQuoteIntegerArray()
	{
		$input = [123, 456];
		$expected = "'123', '456'";

		$db = Db::newDb('testdb');

		$this->assertSame($expected, $db->quote($input));
	}

	/**
	 * Get ID of current connection.
	 *
	 * @param Db $db Db instance.
	 *
	 * @return string
	 */
	protected function _getConnectionId(Db $db): string
	{
		return $db->query('SELECT CONNECTION_ID() AS id')->fetchObject()->id;
	}
}
