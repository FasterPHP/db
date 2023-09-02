<?php
/**
 * Unit Testing Bootstrap.
 */
declare(strict_types=1);

namespace FasterPhp\Db;

require_once __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL);
date_default_timezone_set('Europe/London');

// Pass database config to Db Connection Manager class
ConnectionManager::setConfig([
	'databases' => [
		'testdb' => [
			'dsn' => "mysql:host=127.0.0.1;dbname=testdb;charset=latin1",
			'username' => 'root',
			'password' => '',
			'options' => [
				\PDO::ATTR_TIMEOUT => 5,
				\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
				\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
			],
		],
	],
]);
