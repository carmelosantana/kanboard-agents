# Agents (Kanboard plugin)

Provision API-only **agent users** that drive Kanboard externally via MCP, attributed
through personal-token auth. Any authenticated user manages their own roster; admins see all.

## What it does

- **Create** an agent: mints a Kanboard user `owner.kind[.n]` (role `app-user`, random
  unusable password → API-only), generates a personal API token, and records it in a
  dedicated `agents` roster table.
- **One-time token + MCP snippet:** the personal token is shown **once** on creation,
  alongside a ready-to-paste MCP environment block.
- **List:** own roster for any user; admins see every agent.
- **Disable:** deactivates the agent user (`is_active = 0`), which immediately revokes its
  API token while preserving all history and attribution. The roster row is kept.

Attribution: actions taken with the agent's personal token are attributed to the agent
(`creator_id`), unlike the global app token. Agents remain `app-user` and are granted
project access via Kanboard's native project UI (owner adds the agent as a project member).

## Work in progress view (0.3.0)

- **Page:** avatar menu → **Work in progress** (also in the dashboard sidebar, and a badge above the
  header when something is flagged). A ranked queue by default; **By owner** regroups the same rows
  into one lane per owner. One-click **Move to Done** (Closed, not in Done; All subtasks done) and
  **Assign** (No owner); every other fix links to the ticket. Each fix posts
  `🧭 WIP view: <action> (<flag>) by <user>`.
- **Show unflagged** adds In-progress tickets that have no flag.
- **Flags:** `catalogue.json` is the canonical catalogue (weights, labels, thresholds);
  `catalogue_version` is its sha256. Flags whose data does not exist yet (Location, PR state)
  report `null` counts and are listed in `unavailable_flags`.
- **JSON-RPC:**
  - `getWipFlags(owner_user_id?, scope = all|agents|mine, project_ids?, include_unflagged = false, closed_lookback_days = 30)`
    — read-only. The application token may call it and must pass `owner_user_id`. Refusals come back
    in `denied: {user_ids, project_ids}`, never as an error.
  - `applyWipFix(task_id, action = move_to_done|assign, expected_date_modification, assignee_id?)` →
    `{ok: true, task_id, action, comment_id}` or `{ok: false, reason}`. Refuses the application token.
  - `adoptAgent(agent_user_id, owner_user_id, kind)` — app-admin only: registers an existing user as an
    agent of an owner (also on My Agents for admins).

## 0.3.3 — Time missing on

- **Time missing** (`timemiss`) is now evaluated: an agent-owned ticket with a Location
  (`loc_session_id`) that ended — moved to Done, closed, or its session `ended` — more than 24 h ago
  and whose `time_backfilled_at` stamp is missing, non-numeric or older than that end.

## 0.3.2 — batch roster lookup

- `AgentTable::agentIds(array $userIds)` — batch roster lookup; used by the Presence plugin to badge agents.

## 0.3.1 — provenance relabel

- Schema **v3** runs once on load: every task stamped `moved_by_kind=human` whose `moved_by_uid` is in
  the `agents` roster becomes `agent`. It repairs boards where the v2 back-fill ran before the roster
  was adopted (`adoptAgent`). `system` / uid-0 stamps and non-roster human stamps are left alone.
- One-click buttons now reflect what the viewer may actually do: viewers, restricted roles, the app
  token, and agents on their owner's tickets see a link instead. Adopting a user relabels their
  earlier moves as agent moves.

## Requirements

- Kanboard `>= 1.2.47`, PHP `>= 8.4`. Buildless. No dependency on other suite plugins.

## Install

Copy this directory to `plugins/Agents/` in your Kanboard instance (or clone it there).
The `agents` table is created automatically on first load via the plugin schema mechanism
(ships Sqlite/Mysql/Postgres drivers).

## Usage

Open the top-right avatar menu → **My Agents** → pick a kind (claude / codex / ollama) and
create. Copy the token and the MCP env snippet immediately — the token is not shown again.

```
KANBOARD_API_ENDPOINT=<instance>/jsonrpc.php
KANBOARD_AUTH_METHOD=user_token
KANBOARD_USERNAME=<owner.kind[.n]>
KANBOARD_API_KEY=<personal token>
KANBOARD_USER_APP_ROLES=app-user
```

## Tests

```
./testing/run-plugin-tests.sh Agents
```

(Runs the plugin's PHPUnit suite against a Kanboard core checkout using SQLite `:memory:`.)

## License

MIT © Carmelo Santana
