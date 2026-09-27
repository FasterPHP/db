# Design

## Context

`Db` extends `PDO` and delegates to a lazily created inner `PDO`. It tracks its own `$inTransactionFlag`, set by `beginTransaction()` and cleared only when `commit()` or `rollBack()` succeed, or when `setPdo(null)` discards the connection. `attemptWithReconnect()` calls `assertNotInTransaction()` before reconnecting, and that check trusts the flag first because the live connection may be dead.

The consequence (see proposal.md, Why) is that a connection lost mid-transaction leaves the flag set permanently: `commit()` and `rollBack()` both throw on the dead connection, neither clears the flag, and every later reconnectable failure is converted into `DbException('Connection lost during transaction')`.

`beginTransaction()` already goes through `attemptWithReconnect()`, so a stale idle connection is transparently replaced before a transaction starts. `commit()` and `rollBack()` deliberately do not reconnect.

## Goals / Non-Goals

**Goals:**
- One public method that gives correct owned/joined transaction semantics for a callable.
- Recover the instance's transaction state after a connection loss inside an owned transaction.
- Keep existing public methods' behaviour and signatures unchanged.

**Non-Goals:**
- Savepoints or true nested transactions. A nested call joins the outer transaction; an inner failure that the outer work catches does not undo the inner writes.
- Automatic retry of the work after deadlocks, lock wait timeouts or connection loss. Retrying is a caller policy decision because the work may have non-database side effects.
- Changing `commit()` or `rollBack()` to reset state themselves on failure. That would alter existing behaviour for callers who manage transactions manually; it can be considered separately.
- Recovering or determining the outcome of a commit whose acknowledgement was lost.

## Decisions

### Instance method on `Db`, not a static helper
`$db->transaction(fn () => ...)` rather than `Db::transaction(PDO $pdo, ...)`. The recovery behaviour needs access to `setPdo(null)` and the transaction flag, which only exist on `Db`. A static method accepting any `PDO` would have to branch on `instanceof Db` to do the reset, and would invite use with plain `PDO` instances that get none of the recovery. Callers that type-hint `PDO` and want this method should type-hint `Db`.

Alternative considered: a separate `FasterPhp\Db\Transaction` class. Rejected for the same reason, and because it adds a class for a single method.

### Name: `transaction()`
Reads naturally at the call site and matches the name used by several widely used PHP database layers, so it is familiar. `runInTransaction()` was considered; `executeTransaction()` was rejected because it suggests executing a transaction object. There is no `PDO::transaction()`, so no conflict with the parent class.

### Ownership decided once, on entry, via `inTransaction()`
Whether the call owns the transaction is computed before any work runs and not re-evaluated. At commit or rollback time `inTransaction()` would report the call's own transaction, so a late check would make every call look like a joiner. Using the live `inTransaction()` (rather than only the internal flag) means transactions opened with raw SQL `BEGIN` are also joined, consistent with the existing README statement that raw SQL transactions are recognised.

### Rollback failure resets the connection with `setPdo(null)`
When an owned rollback throws (or returns `false`), the method calls `setPdo(null)`. This clears the flag and drops the inner `PDO`; the next operation lazily reconnects through `attemptWithReconnect()`. This is safe because a failed rollback means either the connection is gone (the server has already discarded the transaction) or the connection is in an unknown state that should not be reused. When the rollback succeeds, no reset happens and the existing connection is kept.

The rollback failure is swallowed and the original throwable is rethrown. Wrapping it (for example attaching the rollback failure as a previous exception) was rejected: the original throwable's class is what callers catch on, and PHP exceptions cannot be given a new `previous` after construction.

### A `false` return from `commit()` is a failure
Under `ERRMODE_SILENT` or `ERRMODE_WARNING`, `commit()` can return `false` instead of throwing. The method treats this as a failure: it throws a `DbException` from inside the guarded block so the normal rollback path runs. Returning normally would tell the caller the work was committed when it was not. `ERRMODE_EXCEPTION` (the PHP 8 default) is unaffected.

### Catch `Throwable`, not `Exception`
The work may fail with an `Error` (for example `TypeError`), and a mysqlnd warning converted by a global error handler can surface as `ErrorException` before `PDOException`, as already handled in `attemptWithReconnect()`. All of these must roll back.

## Risks / Trade-offs

- [Commit acknowledgement lost: the server may have committed but the client sees an error] → The method rethrows the commit error and resets the connection; it cannot know the outcome. Documented in the README as an inherent limitation; callers needing certainty must use idempotent work or verify afterwards.
- [Joined calls silently share the outer transaction, so an inner failure caught by the outer work still commits the inner writes] → Documented in the method docblock and README; savepoints are an explicit non-goal.
- [`setPdo(null)` also discards the statement cache's underlying statements] → Existing `DbStatement` instances already re-prepare after reconnect, so cached statements continue to work; covered by the reconnect test.
- [Tests depend on MySQL `wait_timeout` and `sleep()`, as the existing reconnect tests do] → Reuse the existing pattern; the new reconnect tests add a few seconds to the suite.
