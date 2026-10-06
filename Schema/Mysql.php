<?php
namespace Kanboard\Plugin\Agents\Schema;

const VERSION = 2;

function version_1($pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS agents (
        id INT NOT NULL AUTO_INCREMENT,
        owner_user_id INT NOT NULL,
        agent_user_id INT NOT NULL,
        kind VARCHAR(50) NOT NULL,
        created_at INT NOT NULL DEFAULT 0,
        PRIMARY KEY(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function version_2($pdo)
{
    require_once __DIR__.'/../Model/ProvenanceBackfill.php';
    \Kanboard\Plugin\Agents\Model\ProvenanceBackfill::run($pdo);
}
