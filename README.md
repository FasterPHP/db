# FasterPHP/Db

**FasterPHP/Db** is a high-performance, drop-in replacement for PHP's native `PDO` class. It enhances standard database operations with advanced features designed for robust, modern applications — all while retaining full compatibility with `PDO` interfaces and expectations.

If you're tired of dealing with intermittent MySQL timeouts or disconnects, repetitive statement preparation, or clunky connection logic, this package gives you a clean, extensible, and efficient solution that just works — no learning curve required.

Key features:

- ✅ Drop-in `PDO` replacement (extends `PDO`, not just wraps it)
- 🔁 Auto-reconnect on lost or timed-out connections
- 🔄 Lazy-loading and internal connection pooling
- ⚡️ Prepared statement caching for reduced overhead
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

You can configure reconnect patterns via `Config\ArrayProvider` or inject a custom `ReconnectStrategy`.

---

## ⚙️ Statement Caching

Prepared statements are cached internally to avoid repeated preparation overhead:

```php
$stmt1 = $db->prepare('SELECT * FROM users WHERE id = :id');
$stmt2 = $db->prepare('SELECT * FROM users WHERE id = :id');
// $stmt1 === $stmt2
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
