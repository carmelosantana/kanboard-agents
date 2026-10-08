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

    /** Register an existing user as an agent of an owner. False when the user is already on the roster. */
    public function adopt($ownerUserId, $agentUserId, $kind): bool
    {
        if ($this->getByAgent($agentUserId) !== null) {
            return false;
        }
        return (bool) $this->insert($ownerUserId, $agentUserId, $kind);
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

    public function isAgent(int $userId): bool
    {
        return $userId > 0 && $this->getByAgent($userId) !== null;
    }

    // Batch roster lookup for callers that must not read this table directly (Presence).
    public function agentIds(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) {
            return [];
        }
        $ids = $this->db->table(self::TABLE)->in('agent_user_id', $userIds)->findAllByColumn('agent_user_id');
        return array_map('intval', $ids);
    }
}
