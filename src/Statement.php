<?php
/**
 * Db Statement class.
 *
 * Proxy of PDOStatement to allow auto-reconnect.
 */
declare(strict_types=1);

namespace FasterPhp\Db;

use PDOStatement;

/**
 * Db Statement class.
 */
class Statement
{
	/**
	 * Methods for which to use auto-reconnect.
	 *
	 * @const array
	 */
	protected const AUTORECONNECT_METHODS = ['execute'];

	/**
	 * Instance of main Db class.
	 *
	 * @var Db
	 */
	protected Db $_db;

	/**
	 * PDOStatement instance.
	 *
	 * @var PDOStatement
	 */
	protected PDOStatement $_pdoStatement;

	/**
	 * SQL templates used by PDOStatement.
	 *
	 * @var string
	 */
	protected string $_sql;

	/**
	 * Any driver-specific options used by PDOStatement.
	 *
	 * @var array
	 */
	protected array $_options;

	/**
	 * Object constructor.
	 *
	 * @param Db           $db           Instance of main Db class.
	 * @param PDOStatement $pdoStatement PDOStatement instance.
	 * @param string       $sql          SQL templates used by PDOStatement.
	 * @param array        $options      Any driver-specific options used by PDOStatement.
	 */
	public function __construct(Db $db, PDOStatement $pdoStatement, string $sql, array $options = [])
	{
		$this->_db = $db;
		$this->setPdoStatement($pdoStatement);
		$this->_sql = $sql;
		$this->_options = $options;
	}

	/**
	 * Set PDOStatement instance.
	 *
	 * @param PDOStatement $pdoStatement PDOStatement instance.
	 *
	 * @return void
	 */
	public function setPdoStatement(PDOStatement $pdoStatement): void
	{
		$this->_pdoStatement = $pdoStatement;
	}

	/**
	 * Getter for PDOStatement.
	 *
	 * @return PDOStatement
	 */
	public function getPdoStatement(): PDOStatement
	{
		return $this->_pdoStatement;
	}

	/**
	 * Magic method to implement all remaining PDOStatement methods, and auto-reconnect where required.
	 *
	 * @param string $method    Name of method being called.
	 * @param array  $arguments Array of any method arguments.
	 *
	 * @return mixed
	 */
	public function __call($method, $arguments)
	{
		if (in_array($method, self::AUTORECONNECT_METHODS)) {
			return $this->_autoReconnectOnFail($method, $arguments);
		}

		return call_user_func_array([$this->_pdoStatement, $method], $arguments);
	}

	/**
	 * Execute method and reconnect if necessary.
	 *
	 * @param string $method    Name of method being called.
	 * @param array  $arguments Array of any method arguments.
	 *
	 * @return mixed The value returned from the method.
	 */
	protected function _autoReconnectOnFail(string $method, array $arguments)
	{
		// Note: Due to a bug in mysqlnd, this is needed even when using exceptions!
		set_error_handler([$this, 'errorHandler'], E_WARNING);

		try {
			$result = call_user_func_array([$this->_pdoStatement, $method], $arguments);
			restore_error_handler();
			return $result;
		} catch (\Exception $ex) {
			restore_error_handler();

			// Check whether it's an issue we can handle by reconnecting
			if (0 === preg_match(ConnectionManager::getAutoReconnectErrorsRegex(), $ex->getMessage())) {
				throw $ex;
			}

			// Discard broken connection
			$this->_db->setPdo(null);

			// Force creation of a new PDOStatement
			$this->_db->prepare($this->_sql, $this->_options, $this);

			// Retry once
			return call_user_func_array([$this->_pdoStatement, $method], $arguments);
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
