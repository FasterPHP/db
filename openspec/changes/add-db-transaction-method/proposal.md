# Proposal

## Why

Every consumer of `Db` that wants "run this unit of work atomically" has to hand-write the same begin/commit/rollback boilerplate, and the obvious hand-written version has two traps: a failing rollback can mask the original exception, and nested units of work that each begin their own transaction fail because PDO does not support nested transactions. There is also a latent defect in `Db` itself: when the connection is lost during a transaction, both `commit()` and `rollBack()` throw before clearing the internal transaction flag, so every later operation on that instance throws `DbException('Connection lost during transaction')` and the instance never recovers. A transaction helper that lives inside `Db` can provide the boilerplate once and reset that state correctly.

## What Changes

- Add a public instance method `Db::transaction(callable $work): mixed` that runs `$work` inside a transaction and returns its result.
- If no transaction is active on entry, the method owns the transaction: it begins, runs the work, and commits. On any `Throwable` it rolls back and rethrows the original exception, even when the rollback itself fails.
- If a transaction is already active on entry (via `beginTransaction()` or raw SQL `BEGIN`), the method joins it: it runs the work without beginning, committing or rolling back, and lets any exception propagate untouched to the owner.
- When an owned transaction's rollback fails (typically because the connection has gone), the method discards the dead connection so the transaction flag is cleared and the next operation on the instance can reconnect through the normal auto-reconnect path.
- Document the method in the README alongside the existing transaction-aware reconnect section.

No breaking changes: existing `beginTransaction()`, `commit()`, `rollBack()` and `inTransaction()` behaviour is unchanged.

## Capabilities

### New Capabilities
- `managed-transactions`: running a callable as a single transaction on a `Db` instance, including ownership versus joining, commit and rollback semantics, exception propagation, and recovery of the instance after a connection loss during the transaction.

### Modified Capabilities
<!-- None: no existing specs in openspec/specs/. -->

## Impact

- **Code**: `src/Db.php` (new public method; no changes to existing method signatures).
- **Tests**: `tests/DbTest.php` (commit, rollback, return value, joining) and `tests/DbReconnectAndCacheTest.php` (connection loss during an owned transaction, followed by a successful operation on the same instance).
- **Docs**: `README.md`.
- **API**: additive only. Subclasses of `Db` that already declare a `transaction()` method with an incompatible signature would conflict; none exist in this package.
- **Dependencies**: none.
