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
