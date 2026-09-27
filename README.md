# FasterPHP/Db

**FasterPHP/Db** is a high-performance, drop-in replacement for PHP's native `PDO` class. It enhances standard database operations with advanced features designed for robust, modern applications — all while retaining full compatibility with `PDO` interfaces and expectations.

If you're tired of dealing with intermittent MySQL timeouts or disconnects, repetitive statement preparation, or clunky connection logic, this package gives you a clean, extensible, and efficient solution that just works — no learning curve required.

Key features:

- ✅ Drop-in `PDO` replacement (extends `PDO`, not just wraps it)
- 🔁 Auto-reconnect on lost or timed-out connections with configurable retry/backoff
- 🛡️ Transaction-aware reconnect protection
- 🔒 Managed transactions via `transaction()`, with safe nesting
- 🔄 Lazy-loading and internal connection pooling
- ⚡️ Prepared statement caching for reduced overhead
- 📝 Optional PSR-3 logging support
- 🧱 Custom `DbStatement` class for enhanced control

---

## 🚀 Installation

```bash
composer require fasterphp/db
```

---

## ✅ Requirements

- PHP 8.2 or higher
- A supported PDO database (e.g. MySQL, PostgreSQL, SQLite, etc.)
- PDO extension (`ext-pdo`)

---

## 📦 Basic Usage

```php
use FasterPhp\Db\Db;

$db = new Db(
    dsn: 'mysql:host=localhost;dbname=mydb',
    username: 'myuser',
    password: 'mysecret',
);

$stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
$stmt->execute([':id' => 1]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
```

---

## 🔁 Auto-Reconnect

If a connection is lost (e.g. due to a timeout), FasterPhp\Db automatically reconnects, re-prepares (where necessary), and re-executes the statement.

### Configurable Retry with Exponential Backoff

The `DefaultStrategy` supports configurable retry attempts with exponential backoff:

```php
use FasterPhp\Db\Db;
use FasterPhp\Db\Reconnect\DefaultStrategy;

$strategy = new DefaultStrategy(
    maxAttempts: 3,        // Try up to 3 times (default: 1)
    baseDelayMs: 100,      // Start with 100ms delay (default: 100)
    backoffMultiplier: 2.0 // Double the delay each attempt (default: 2.0)
);

$db = new Db(
    dsn: 'mysql:host=localhost;dbname=mydb',
    username: 'myuser',
    password: 'mysecret',
    reconnectStrategy: $strategy
);
```

With these settings, reconnect attempts will wait 100ms, then 200ms, then 400ms between retries.

### Transaction-Aware Reconnect Protection

Reconnecting mid-transaction would silently lose uncommitted changes. FasterPhp\Db prevents this by throwing a `DbException` if a reconnectable error occurs during an active transaction:

```php
$db->beginTransaction();
$db->exec('INSERT INTO users (name) VALUES ("Alice")');
// If connection is lost here, DbException is thrown instead of reconnecting
// This prevents silent data loss
```

This protection works for both explicit transactions (`beginTransaction()`) and raw SQL transactions (`BEGIN`/`START TRANSACTION`).

### Managed Transactions

`transaction()` runs a callable as a single transaction and returns its result:

```php
$userId = $db->transaction(function () use ($db) {
    $db->prepare('INSERT INTO users (name) VALUES (?)')->execute(['Alice']);
    $userId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO audit (user_id) VALUES (?)')->execute([$userId]);
    return $userId;
});
```

If no transaction is active, `transaction()` owns one: it begins, runs the work and commits. If the work throws, it rolls back and rethrows the original exception, even if the rollback itself fails.

If a transaction is already active (from `beginTransaction()`, raw SQL, or an enclosing `transaction()` call), the work joins it: nothing is begun, committed or rolled back, and any exception propagates to the owner. This lets transactional methods call each other safely:

```php
function createUser(Db $db, string $name): int
{
    return $db->transaction(function () use ($db, $name) {
        $db->prepare('INSERT INTO users (name) VALUES (?)')->execute([$name]);
        return (int)$db->lastInsertId();
    });
}

$db->transaction(function () use ($db) {
    createUser($db, 'Alice');
    createUser($db, 'Bob');
}); // Both users are committed together, or neither is
```

Points to be aware of:

- **Recovery after connection loss:** if the connection is lost inside an owned transaction, the original exception is rethrown and the connection is discarded, so the next operation reconnects normally rather than being refused as a lost transaction.
- **No retries:** the work is run at most once. Retrying after a deadlock or connection loss is left to the caller, since the work may have side effects outside the database.
- **No savepoints:** a nested call shares the outer transaction. If the outer work catches an exception from a nested call, the nested call's writes are still committed with the rest.
- **Lost commit acknowledgement:** if the connection drops while the commit is in flight, the server may or may not have committed. The error is rethrown, but the outcome cannot be known from the client.

### PSR-3 Logging

You can attach a PSR-3 compatible logger to monitor reconnect events:

```php
use Psr\Log\LoggerInterface;

$db->setLogger($logger);

// Reconnect events are logged at WARNING level with DSN and error details
```

### Custom Reconnect Strategy

You can configure reconnect patterns via `Config\ArrayProvider` or inject a custom `ReconnectStrategy` by implementing `Reconnect\StrategyInterface`.

---

## ⚙️ Statement Caching

Prepared statements are cached internally to avoid repeated preparation overhead:

```php
$stmt1 = $db->prepare('SELECT * FROM users WHERE id = :id');
$stmt2 = $db->prepare('SELECT * FROM users WHERE id = :id');
// $stmt1 === $stmt2
```

To clear the statement cache (e.g. after schema changes or to free memory):

```php
$db->clearStatementCache();
```

---

## 🔧 Advanced Usage

### 📡 ConnectionManager

The `ConnectionManager` class enables application-wide connection pooling and shared configuration. It’s ideal for large applications, services, or frameworks that require multiple named database instances or shared lifecycle control.

```php
use FasterPhp\Db\ConnectionManager;
use FasterPhp\Db\Config\ArrayProvider;

$config = new ArrayProvider([
    'main' => [
        'dsn' => 'mysql:host=127.0.0.1;dbname=main_db',
        'username' => 'root',
        'password' => '',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ],
    ],
    'analytics' => [
        'dsn' => 'mysql:host=127.0.0.1;dbname=analytics',
        'username' => 'readonly',
        'password' => '',
    ],
]);

$manager = new ConnectionManager($config);

// Get a shared Db instance
$db = $manager->get('main');
```

> 💡 `ConnectionManager` will automatically reuse idle connections if available.

---

### ⚙️ Configuration Providers

The `Config\ArrayProvider` class is a simple implementation of the `ProviderInterface` that lets you define connection settings in plain PHP arrays.

To use a different configuration source (like `.env`, JSON, or YAML), implement:

```php
interface ProviderInterface {
    public function get(string $name): ?array;
}
```

This keeps your configuration logic separate from your application logic, and makes it easy to test or extend.

---

## 🧪 Testing

Tests require a MySQL server with a `testdb` database. You can configure your environment using `.env` variables or `phpunit.xml`:

```
DB_DSN="mysql:host=127.0.0.1;dbname=testdb"
DB_USER="root"
DB_PASS=""
```

Run tests with:

```bash
vendor/bin/phpunit
```

To generate a coverage report (requires Xdebug or PCOV):

```bash
vendor/bin/phpunit --coverage-html build/coverage
```

---

## 🤝 Contributing

Contributions are welcome! To get started:

1. Fork the repository
2. Create a new branch (`git checkout -b feature/my-feature`)
3. Commit your changes (`git commit -am 'Add feature'`)
4. Push to the branch (`git push origin feature/my-feature`)
5. Open a pull request

---

## 🧭 Code of Conduct

Please be respectful and constructive in all interactions. We aim to foster a professional, welcoming environment.

---

## 🔐 Security

If you discover a security vulnerability, please report it privately via GitHub or email the maintainer. Avoid opening public issues for sensitive disclosures.

---

## 📄 License

This package is open-source software licensed under the [MIT license](LICENSE.md).
