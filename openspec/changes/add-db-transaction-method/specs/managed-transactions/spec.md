# Spec Delta

## Purpose

Lets callers run a unit of work atomically on a `Db` instance with a single call, without hand-writing begin/commit/rollback handling, while composing safely with transactions that are already open and leaving the instance usable after a connection loss.

## ADDED Requirements

### Requirement: Run work in an owned transaction
When no transaction is active on the connection at the point of the call, `Db::transaction()` SHALL begin a transaction, invoke the supplied callable with no arguments, commit the transaction, and return the callable's return value unchanged.

#### Scenario: Work succeeds and is committed
- **WHEN** `transaction()` is called with no transaction active and the callable inserts a row and returns normally
- **THEN** the transaction is committed, the row is visible to a subsequent query, and no transaction is active afterwards

#### Scenario: Return value is passed through
- **WHEN** the callable returns a value (including `null`, `false` or an object)
- **THEN** `transaction()` returns that exact value

#### Scenario: Stale idle connection before the call
- **WHEN** the connection has been dropped while idle and `transaction()` is called with no transaction active
- **THEN** the instance reconnects before beginning the transaction, under the existing auto-reconnect rules, and the work runs and commits normally

### Requirement: Roll back and rethrow on failure
When the callable of an owned transaction throws any `Throwable`, `transaction()` SHALL roll back the transaction and rethrow the original throwable, preserving its class, message and identity.

#### Scenario: Work throws
- **WHEN** the callable inserts a row and then throws an exception
- **THEN** the same exception instance propagates to the caller, the inserted row is not persisted, and no transaction is active afterwards

#### Scenario: Error rather than exception
- **WHEN** the callable throws an `Error` (for example a `TypeError`)
- **THEN** the transaction is rolled back and the same `Error` instance propagates

#### Scenario: Commit fails
- **WHEN** the callable returns normally but the commit throws
- **THEN** a rollback is attempted and the commit's throwable propagates to the caller

#### Scenario: Commit reports failure without throwing
- **WHEN** the connection is configured with a non-exception error mode and the commit returns `false`
- **THEN** a rollback is attempted and a `DbException` propagates to the caller, rather than `transaction()` returning as if the work had been committed

### Requirement: Rollback failure does not mask the original cause
If the rollback of an owned transaction itself fails, `transaction()` SHALL suppress the rollback failure and rethrow the throwable that triggered the rollback.

#### Scenario: Rollback throws after work failure
- **WHEN** the callable throws exception A and the subsequent rollback throws exception B
- **THEN** exception A propagates to the caller and exception B is not thrown

### Requirement: Instance remains usable after connection loss in a transaction
If the rollback of an owned transaction fails, `transaction()` SHALL leave the `Db` instance with no transaction recorded as active, so that the next operation on the instance is eligible for auto-reconnect instead of being refused as a connection lost during a transaction.

#### Scenario: Connection lost during the work
- **WHEN** the connection is lost while the callable is running inside an owned transaction, causing the work to throw
- **THEN** the original throwable propagates from `transaction()`, and a subsequent operation on the same instance reconnects and succeeds

#### Scenario: Connection lost before commit
- **WHEN** the callable returns normally but the connection has been lost, so the commit and the rollback both throw
- **THEN** the commit's throwable propagates from `transaction()`, and a subsequent operation on the same instance reconnects and succeeds

### Requirement: Join an already active transaction
When a transaction is already active on the connection at the point of the call, whether opened through `beginTransaction()`, raw SQL, or an enclosing `transaction()` call, `transaction()` SHALL invoke the callable without beginning, committing or rolling back, return its result, and let any throwable propagate unchanged.

#### Scenario: Nested call inside an owned transaction
- **WHEN** a `transaction()` callable itself calls `transaction()` with a callable that inserts a row, and the outer callable then returns normally
- **THEN** both levels' writes are committed exactly once, by the outer call

#### Scenario: Inner failure is handled by the owner
- **WHEN** a nested `transaction()` callable throws and the exception is not caught by the outer callable
- **THEN** the exception propagates through the inner call without a rollback there, and the outer call rolls back all writes and rethrows the same exception

#### Scenario: Joining a manually opened transaction
- **WHEN** `beginTransaction()` has been called and `transaction()` is then called with a callable that throws
- **THEN** the exception propagates, the transaction is still active, and the caller remains responsible for committing or rolling it back

### Requirement: Work is never retried
`transaction()` SHALL invoke the callable at most once per call and SHALL NOT re-run it after a failure, including failures caused by a lost connection.

#### Scenario: Connection lost during the work is not replayed
- **WHEN** the callable fails because the connection was lost
- **THEN** the callable has been invoked exactly once when `transaction()` throws
