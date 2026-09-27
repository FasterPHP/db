# Tasks

## 1. Owned transactions

- [x] 1.1 Add `public function transaction(callable $work): mixed` to `src/Db.php`, next to `beginTransaction()`/`commit()`/`rollBack()`, with a docblock covering owned versus joined behaviour and a `@template T` / `@param callable(): T` / `@return T` annotation. Decide ownership once on entry with `inTransaction()`; when owning, call `beginTransaction()`, run the work, call `commit()`, and return the result. Verify with `vendor/bin/phpcs src/Db.php` passing.
- [x] 1.2 Inside the guarded block, throw a `DbException('Transaction commit failed')` when `commit()` returns `false`. Verify via task 1.4.
- [x] 1.3 On any `Throwable`, call `rollBack()` inside its own `try`; if it throws or returns `false`, call `$this->setPdo(null)` and swallow the rollback failure; then rethrow the original throwable. Verify via tasks 1.4 and 2.2.
- [x] 1.4 Add tests to `tests/DbTest.php` and verify they pass: commit persists a row and leaves no active transaction; return values (`null`, `false`, an object) pass through identically; a thrown exception and a thrown `Error` roll back the insert and propagate as the same instance (`assertSame`); an anonymous subclass whose `commit()` returns `false` causes a `DbException` and no persisted row; an anonymous subclass whose `rollBack()` throws still propagates the work's original exception.

## 2. Recovery after connection loss

- [x] 2.1 Confirm by test (task 2.2) that `setPdo(null)` after a failed rollback leaves `inTransactionFlag` cleared and that no additional code changes to `commit()`, `rollBack()` or `assertNotInTransaction()` are needed.
- [x] 2.2 Add tests to `tests/DbReconnectAndCacheTest.php`, using the existing `SET SESSION wait_timeout = 1` plus `sleep(2)` pattern, and verify they pass: (a) connection lost while the work runs: the work's throwable propagates, the work was invoked exactly once, and a following `$db->query('SELECT 1')` succeeds on a new connection id; (b) connection lost after the work but before commit: the commit's throwable propagates and a following query succeeds on a new connection id; (c) a stale idle connection before the call: `transaction()` reconnects, runs the work and commits. Check the run reports no unexpected PHP warnings from the dead-connection rollback.
- [x] 2.3 Add a test that a previously cached `DbStatement` still executes after recovery in 2.2(a). Verify it passes.

## 3. Joining existing transactions

- [x] 3.1 Add tests to `tests/DbTest.php` and verify they pass: nested `transaction()` calls commit both levels' writes once via the outer call; an uncaught inner exception propagates as the same instance and the outer call rolls back all writes; inside a manual `beginTransaction()`, a throwing `transaction()` call propagates the exception and leaves `inTransaction()` true, with the test rolling back afterwards; a transaction opened with raw SQL `START TRANSACTION` is joined rather than a second transaction being begun.

## 4. Documentation

- [x] 4.1 Add a "Managed Transactions" subsection to `README.md` after "Transaction-Aware Reconnect Protection", showing `$db->transaction(fn () => ...)` usage and a nested call, and stating: owned versus joined behaviour, that the original exception is always rethrown, that the instance recovers after a connection loss, that the work is never retried, that nested calls do not use savepoints, and that a commit whose acknowledgement is lost has an unknown outcome. Verify the example code is syntactically valid with `php -l` on an extracted snippet.

## 5. Integration check

- [x] 5.1 Run the full suite with `vendor/bin/phpunit` and `vendor/bin/phpcs src tests`, and verify both pass with no new warnings or deprecations.
