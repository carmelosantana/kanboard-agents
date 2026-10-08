# Plan: make `createAgent` atomic (Kanboard #6072)

Spec: Kanboard #6072's description, which follows up Kanboard #4568 and Kanboard #6045 (Plan 5 Task 4, Agents 0.4.0). There is no separate spec file.

## Context

`Model/AgentProvisioner::create($ownerUserId, $kind, $label)` makes three writes:

1. `userModel->create([...])` inserts the user and returns the id or `false`. It is checked.
2. `db->table(users)->eq('id', $agentId)->update(['api_access_token' => $token])` is **not checked**.
3. `(new AgentTable)->insert($ownerUserId, $agentId, $kind)` is **not checked**.

Both the admin JSON-RPC `createAgent` (via `createForApi`, which catches `\Throwable` and returns
`{ok:false, reason:'create_failed'}`) and the My Agents UI (`Controller/AgentController::create`) call `create()`.
A failure at step 2 or 3 leaves a half-made agent behind.

PicoDb facts (`testing/kanboard-src/libs/picodb/lib/PicoDb/`):
- `Database::startTransaction()` begins a transaction only when none is open, so calls don't nest.
  `closeTransaction()` commits and `cancelTransaction()` rolls back, each only when a transaction is open.
- On a `PDOException`, `StatementHandler::handleSqlError()` **already calls `cancelTransaction()`**. It then
  returns `false` when the driver calls it a duplicate-key error (SQLite: any SQLSTATE 23000 constraint error,
  including a trigger's `RAISE(ABORT, …)`). Otherwise it throws `PicoDb\SQLException`.
  So after a step returns `false`, the transaction is already rolled back. Any later write would run in
  autocommit, which means every step has to stop the sequence immediately.

## Global Constraints

- The success path's return shape is unchanged: `['agent_user_id' => int, 'username' => string, 'token' => string]`.
- `createForApi` keeps every reason exactly as it is (`forbidden`, `invalid_kind`, `unknown_user`, `owner_is_agent`, `create_failed`).
- `create()` either commits all three writes or leaves **nothing**: no user row, no token, no roster row.
  On failure it throws (`\RuntimeException` for a `false` step; any `\Throwable` from PicoDb is rethrown after rollback).
- `create()` owns the transaction only when it opened it. If one was already open (`$this->db->getConnection()->inTransaction()`
  before the start), it never commits. It still makes sure the caller sees an exception on failure.
- Validation before the writes stays outside the transaction: the owner lookup and `nextUsername`.
- No production test seams. Failures are injected only from tests, using SQLite triggers or schema changes.
- Version **0.4.1** is aligned in `plugin.json`, `Plugin::getPluginVersion()` and `Test/PluginVersionTest.php`.
- Tests run with the scratch wrapper (see the dispatch), which is the same phpunit/config as `./testing/run-plugin-tests.sh Agents` but pointed at this worktree.
- The house style for commit messages is Conventional Commits with `(#6072)`, and no attribution lines.

## Task 1: transactional `create()` with per-step failure injection, then 0.4.1

**TDD: write the failing tests first and watch them fail for the right reason.**

Add these tests to `Test/AgentProcedureTest.php`, the JSON-RPC level. Each test, acting as admin, injects one failure,
calls `createAgent($this->owner, 'codex', '')`, and asserts all of the following:
- the result `=== ['ok' => false, 'reason' => 'create_failed']`;
- no user named `carmelo.codex` exists;
- the `users` row count is unchanged from before the call;
- no user has a non-empty `api_access_token`;
- the roster (`agents`) is empty.

Each test then removes the injection and asserts that a retried `createAgent($this->owner, 'codex', '')` succeeds
with username `carmelo.codex`. That proves no leftover took the name.

| Test | Step | Injection |
|---|---|---|
| `testUserInsertFailureLeavesNothing` | 1 | `CREATE TRIGGER t BEFORE INSERT ON users WHEN NEW.username = 'carmelo.codex' BEGIN SELECT RAISE(ABORT, 'injected'); END` |
| `testTokenWriteFailureLeavesNothing` | 2 | `CREATE TRIGGER t BEFORE UPDATE OF api_access_token ON users BEGIN SELECT RAISE(ABORT, 'injected'); END` |
| `testRosterInsertFailureLeavesNothing` | 3, `false` path | `CREATE TRIGGER t BEFORE INSERT ON agents BEGIN SELECT RAISE(ABORT, 'injected'); END` |
| `testRosterInsertExceptionLeavesNothing` | 3, throw path | `ALTER TABLE agents RENAME TO agents_off` (a "no such table" error, so `SQLException` is thrown). Rename it back before the roster assertions. |

Remove injections with `DROP TRIGGER t` (or the reverse rename) through `$this->container['db']->getConnection()->exec()`.

Add these to `Test/AgentProvisionerTest.php`, the model level, which also covers the UI path:
- `testCreateThrowsAndLeavesNothingWhenTheRosterWriteFails`: use the step 3 trigger. `create()` throws, and no user,
  token or roster row is left.
- `testCreateDoesNotCommitACallersTransaction`: call `$db->startTransaction()`, then `create()` (which succeeds),
  then `$db->cancelTransaction()`. Assert that no `carmelo.claude` user and no roster row persist.

Then implement it in `Model/AgentProvisioner::create()`:
- Note `$owned = ! inTransaction()`, then `startTransaction()`.
- Check each step's result. On `false`, throw a `\RuntimeException` that names the step.
- Wrap the three writes in `try { … if ($owned) closeTransaction(); } catch (\Throwable $e) { cancelTransaction(); throw $e; }`.
  Cancelling is safe even when PicoDb already rolled back, because it is a no-op once no transaction is open.
- Keep the method's existing doc comment and add one line saying it is atomic.

Release the same way as v0.4.0:
- Bump `plugin.json` and `Plugin::getPluginVersion()` to `0.4.1`, and update the version test.
- Under the 0.4.0 section's heading in `README.md`, add a `## 0.4.1 — atomic createAgent` section.
  Say that `createAgent` (and My Agents' Create) now runs in one transaction, so a failure at any step leaves no
  user, token or roster row behind. Also update the 0.4.0 `create_failed` note: "(nothing was created; the cause is logged)".

Commit, for example as `fix(api): createAgent is atomic — one transaction, every step checked (#6072); Agents 0.4.1`.
Tests then commits are fine too. The full suite must be green: 179 existing tests plus the new ones.
