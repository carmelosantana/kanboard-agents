<?php
namespace Kanboard\Plugin\Agents\Schema;

const VERSION = 2;

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

function version_2($pdo)
{
    require_once __DIR__.'/../Model/ProvenanceBackfill.php';
    \Kanboard\Plugin\Agents\Model\ProvenanceBackfill::run($pdo);
}
