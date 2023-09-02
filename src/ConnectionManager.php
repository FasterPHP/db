<?php
/**
 * Db Connection Manager class.
 */
declare(strict_types=1);

namespace FasterPhp\Db;

use PDO;

/**
 * Db Connection Manager class.
 */
class ConnectionManager
{
	/**
	 * Errors for which auto-reconnect should be attempted.
	 */
	protected const AUTO_RECONNECT_ERRORS = [
		'MySQL server has gone away',
		'Error while sending QUERY packet',
		'Cannot execute queries while other unbuffered queries are active',
		'Packets out of order',
	];

	/**
	 * Array of database connection parameters.
	 *
	 * @var array
	 */
	protected static array $_config;

	/**
	 * Pool of connections for re-use.
	 *
	 * Format: [dbKey => [connection1, connection2]]
	 *
	 * @var array
	 */
	protected static array $_connectionPool = [];

	/**
	 * Cached regex for identifying auto-reconnect errors.
	 *
	 * @var string
	 */
	protected static string $_autoReconnectErrorsRegex;

	/**
	 * Set database connection parameters.
	 *
	 * @param array $config Db config data.
	 *
	 * @return void
	 */
	public static function setConfig(array $config): void
	{
		self::$_config = $config;
	}

	/**
	 * Get an active, pooled connection if available, or create a new one.
	 *
	 * @param string $dbKey Connection name.
	 *
	 * @return PDO
	 */
	public static function getConnection(string $dbKey): PDO
	{
		return self::_getPooledConnection($dbKey) ?? self::_getNewConnection($dbKey);
	}

	/**
	 * Releases the connection back into the pool.
	 *
	 * @param string $dbKey Connection name.
	 * @param PDO    $pdo   PDO instance.
	 *
	 * @return void
	 */
	public static function addToPool(string $dbKey, PDO $pdo): void
	{
		// Create the array if the key doesn't exist yet.
		if (!isset(self::$_connectionPool[$dbKey])) {
			self::$_connectionPool[$dbKey] = [];
		}

		// Add/return connection to pool
		self::$_connectionPool[$dbKey][] = $pdo;
	}

	/**
	 * Get regex for identifying auto-reconnect errors.
	 *
	 * @return string
	 */
	public static function getAutoReconnectErrorsRegex(): string
	{
		if (!isset(self::$_autoReconnectErrorsRegex)) {
			self::$_autoReconnectErrorsRegex = '/' . implode('|', array_map(function ($error) {
				return preg_quote($error, '/');
			}, self::AUTO_RECONNECT_ERRORS)) . '/i';
		}
		return self::$_autoReconnectErrorsRegex;
	}

	/**
	 * Clear connection pool (for unit testing).
	 *
	 * @return void
	 */
	public static function clearConnectionPool(): void
	{
		self::$_connectionPool = [];
	}

	/**
	 * Get connection from pool, if available.
	 *
	 * @param string $dbKey Connection name.
	 *
	 * @return PDO|null
	 */
	protected static function _getPooledConnection(string $dbKey): ?PDO
	{
		if (!isset(self::$_connectionPool[$dbKey]) || empty(self::$_connectionPool[$dbKey])) {
			return null;
		}
		$pdo = array_shift(self::$_connectionPool[$dbKey]);

		// Make sure connection alive
		try {
			$pdo->query('SELECT 1');
		} catch (\Exception $ex) {
			return null;
		}

		return $pdo;
	}

	/**
	 * Get new connection.
	 *
	 * @param string $dbKey Connection name.
	 *
	 * @return PDO
	 * @throws Exception If connection is missing a PDO DSN.
	 */
	protected static function _getNewConnection(string $dbKey): PDO
	{
		$dbConfig = self::_getDbConfig($dbKey);
		if (empty($dbConfig['dsn'])) {
			throw new Exception("PDO DSN not defined for database '$dbKey'");
		}
		$username = $dbConfig['username'] ?? null;
		$password = $dbConfig['password'] ?? null;
		$options = $dbConfig['options'] ?? null;

		return new PDO($dbConfig['dsn'], $username, $password, $options);
	}

	/**
	 * Get config for current database connection.
	 *
	 * @param string $dbKey Connection name.
	 *
	 * @return array
	 * @throws Exception If config not set.
	 */
	protected static function _getDbConfig(string $dbKey): array
	{
		if (!isset(self::$_config)) {
			throw new Exception('Config not set');
		}

		if (!isset(self::$_config['databases'])) {
			throw new Exception('Databases section missing from db config');
		}

		if (!isset(self::$_config['databases'][$dbKey])
			|| !is_array(self::$_config['databases'][$dbKey])
		) {
			throw new Exception("Database connection name '$dbKey' not found");
		}

		return self::$_config['databases'][$dbKey];
	}
}
