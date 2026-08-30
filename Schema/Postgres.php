<?php
namespace Kanboard\Plugin\Agents\Schema;

const VERSION = 1;

function version_1($pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS agents (
        id SERIAL PRIMARY KEY,
        owner_user_id INTEGER NOT NULL,
        agent_user_id INTEGER NOT NULL,
        kind VARCHAR(50) NOT NULL,
        created_at INTEGER NOT NULL DEFAULT 0
    )');
}
