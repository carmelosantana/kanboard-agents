<?php
namespace Kanboard\Plugin\Agents\Schema;

const VERSION = 3;

function version_1($pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS agents (
        "id" INTEGER PRIMARY KEY,
        "owner_user_id" INTEGER NOT NULL,
        "agent_user_id" INTEGER NOT NULL,
        "kind" TEXT NOT NULL,
        "created_at" INTEGER NOT NULL DEFAULT 0
    )');
}

function version_2($pdo)
{
    require_once __DIR__.'/../Model/ProvenanceBackfill.php';
    \Kanboard\Plugin\Agents\Model\ProvenanceBackfill::run($pdo);
}

function version_3($pdo)
{
    require_once __DIR__.'/../Model/ProvenanceRelabel.php';
    \Kanboard\Plugin\Agents\Model\ProvenanceRelabel::run($pdo);
}
