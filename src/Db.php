<?php
/**
 * Db class.
 *
 * Proxy of PDO to provide lazy-loading of connections (ie only connects when needed),
 * connection-pooling, auto-reconnect and automatic caching of prepared statements.
 */
declare(strict_types=1);

namespace FasterPhp\Db;

use PDO;

/**
 * Db class.
 */
class Db
{
	/**
	 * Methods for which to use auto-reconnect.
	 *
	 * @const array
	 */
	protected const AUTORECONNECT_METHODS = ['exec', 'query'];

	/**
	 * Database connection name (db config key).
	 *
	 * @var string
	 */
	protected string $_dbKey;

	/**
	 * PDO instance.
	 *
	 * @var PDO|null
	 */
	protected ?PDO $_pdo;

	/**
	 * Statement cache.
	 *
	 * @var Statement[]
	 */
	protected static array $_statements = [];

	/**
	 * Create a new instance for the requested database connection.
	 *
	 * @param string $dbKey Database connection name (db config key).
	 *
	 * @return self
	 */
	public static function newDb(string $dbKey): Db
	{
		return new self($dbKey);
	}

	/**
	 * Clear Statement cache (for unit testing).
	 *
	 * @return void
	 */
	public static function clearStatementCache(): void
	{
		self::$_statements = [];
	}

	/**
	 * Object constructor.
	 *
	 * @param string $dbKey Connection name.
	 */
	protected function __construct(string $dbKey)
	{
		$this->_dbKey = $dbKey;
	}

	/**
	 * Get connection name.
	 *
	 * @return string
	 */
	public function getDbKey(): string
	{
		return $this->_dbKey;
	}

	/**
	 * Set PDO instance.
	 *
	 * @param PDO|null $pdo PDO instance, or null to unset.
	 *
	 * @return self
	 */
	public function setPdo(?PDO $pdo): self
	{
		$this->_pdo = $pdo;
		return $this;
	}

	/**
	 * Get PDO instance via lazy-loading.
	 *
	 * @return PDO
	 * @throws Exception If DSN not defined.
	 */
	public function getPdo(): PDO
	{
		if (!isset($this->_pdo)) {
			$this->_pdo = ConnectionManager::getConnection($this->getDbKey());
		}
		return $this->_pdo;
	}

	/**
	 * Prepares a PDOStatement for execution and returns a Statement object.
	 *
	 * @param string    $sql       SQL template.
	 * @param array     $options   Optional, driver-specific options.
	 * @param Statement $statement Optional, existing Statement object to populate with new PDOStatement.
	 *
	 * @return Statement|false
	 */
	public function prepare($sql, $options = [], Statement $statement = null)
	{
		$hash = $this->getDbKey() . '-' . md5($sql);

		// If existing Statement object provided, check it's the same one we have cached
		if (!empty($statement) && (!isset(self::$_statements[$hash]) || self::$_statements[$hash] !== $statement)) {
			throw new Exception("Provided Statement doesn't match cached value!");
		}

		if (!isset(self::$_statements[$hash]) || !empty($statement)) {
			// Note: PdoMySQL actually uses emulated prepared statements by default, which don't
			// need a live connection - but wrap it anyway, just in case this has been disabled.
			$pdoStatement = $this->_autoReconnectOnFail(function () use ($sql, $options) {
				return $this->getPdo()->prepare($sql, $options);
			});

			// Only cache result if successful
			if (false === $pdoStatement) {
				return false; // TODO: Throw exception instead?
			}

			if (empty($statement)) {
				self::$_statements[$hash] = new Statement($this, $pdoStatement, $sql, $options);
			} else {
				$statement->setPdoStatement($pdoStatement);
			}
		}

		return self::$_statements[$hash];
	}

	/**
	 * Wraps PDO's quote method to allow usage for all primative types and arrays.
	 *
	 * @param array|mixed $args          Argument(s) to escape.
	 * @param integer     $parameterType Provides a data type hint for drivers that have alternate quoting styles.
	 *
	 * @return string|false
	 */
	public function quote($args, $parameterType = PDO::PARAM_STR)
	{
		if (is_array($args)) {
			return implode(', ', array_map(function ($arg) use ($parameterType) {
				return $this->getPdo()->quote((string) $arg, $parameterType);
			}, $args));
		}
		return $this->getPdo()->quote((string) $args, $parameterType);
	}

	/**
	 * Magic method to implement all remaining PDO methods, and auto-reconnect where required.
	 *
	 * @param string $method    Name of method being called.
	 * @param array  $arguments Array of any method arguments.
	 *
	 * @return mixed
	 */
	public function __call($method, $arguments)
	{
		if (in_array($method, self::AUTORECONNECT_METHODS)) {
			return $this->_autoReconnectOnFail(function () use ($method, $arguments) {
				return call_user_func_array([$this->getPdo(), $method], $arguments);
			});
		}

		return call_user_func_array([$this->getPdo(), $method], $arguments);
	}

	/**
	 * Destructor.
	 *
	 * Releases the connection back into the pool.
	 */
	public function __destruct()
	{
		// Not connected
		if (!isset($this->_pdo)) {
			return;
		}

		ConnectionManager::addToPool($this->getDbKey(), $this->_pdo);
	}

	/**
	 * Function which wraps the callback to auto-reconnect on disconnection.
	 *
	 * @param \Closure $callback The function to execute and reconnect on failure.
	 *
	 * @return mixed The value returned from the closure.
	 */
	protected function _autoReconnectOnFail(\Closure $callback)
	{
		// Note: Due to a bug in mysqlnd, this is needed even when using exceptions!
		set_error_handler([$this, 'errorHandler'], E_WARNING);

		try {
			$result = $callback();
			restore_error_handler();
			return $result;
		} catch (\Exception $ex) {
			restore_error_handler();

			// Check whether it's an issue we can handle by reconnecting
			if (false === preg_match(ConnectionManager::getAutoReconnectErrorsRegex(), $ex->getMessage())) {
				throw $ex;
			}

			// Close connection and reconnect
			unset($this->_pdo);
			$this->getPdo();

			// Retry once
			return $callback();
		}
	}

	/**
	 * Error handler for converting PHP warnings to exception.
	 *
	 * @param integer $errno  Error number.
	 * @param string  $errstr Error message.
	 *
	 * @return boolean
	 * @throws Exception
	 */
	public function errorHandler($errno, $errstr): bool
	{
		if ($errno !== E_WARNING) {
			return false;
		}

		throw new Exception($errstr);
	}
}
