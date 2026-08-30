<?php
namespace Kanboard\Plugin\Agents\Model;

use Kanboard\Core\Base;

class AgentTable extends Base
{
    const TABLE = 'agents';

    public function insert($ownerUserId, $agentUserId, $kind)
    {
        return $this->db->table(self::TABLE)->insert([
            'owner_user_id' => (int) $ownerUserId,
            'agent_user_id' => (int) $agentUserId,
            'kind'          => $kind,
            'created_at'    => time(),
        ]);
    }

    public function getByOwner($ownerUserId)
    {
        return $this->db->table(self::TABLE)->eq('owner_user_id', (int) $ownerUserId)->asc('id')->findAll();
    }

    public function getAll()
    {
        return $this->db->table(self::TABLE)->asc('id')->findAll();
    }

    public function getByAgent($agentUserId)
    {
        $row = $this->db->table(self::TABLE)->eq('agent_user_id', (int) $agentUserId)->findOne();
        return $row ?: null;
    }
}
