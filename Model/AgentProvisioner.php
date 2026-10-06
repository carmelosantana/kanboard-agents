<?php
namespace Kanboard\Plugin\Agents\Model;

use Kanboard\Core\Base;
use Kanboard\Core\Security\Token;
use Kanboard\Core\Security\Role;

class AgentProvisioner extends Base
{
    /** Create an API-only agent user owned by $ownerUserId. Returns username/token/agent id. */
    public function create($ownerUserId, $kind, $label = '')
    {
        $owner = $this->userModel->getById($ownerUserId);
        if (empty($owner)) {
            throw new \LogicException('Unknown owner user '.$ownerUserId);
        }

        $username = $this->nextUsername($owner['username'], $kind);
        $name = $label !== '' ? $label : ($owner['username']."'s ".$kind);

        $agentId = $this->userModel->create([
            'username' => $username,
            'password' => bin2hex(random_bytes(24)), // random, never surfaced -> API-only
            'name'     => $name,
            'role'     => Role::APP_USER,
        ]);
        if ($agentId === false) {
            throw new \RuntimeException('Failed to create agent user '.$username);
        }

        $token = Token::getToken();
        $this->db->table(\Kanboard\Model\UserModel::TABLE)->eq('id', $agentId)->update(['api_access_token' => $token]);

        // Owner-group is deferred from MVP (no consumer yet); the agents table is the roster.
        (new AgentTable($this->container))->insert($ownerUserId, $agentId, $kind);

        return ['agent_user_id' => (int) $agentId, 'username' => $username, 'token' => $token];
    }

    /**
     * Admin-only: put an existing user on an owner's roster (Kanboard #4882).
     * Never throws for authorization; returns {ok: false, reason} instead.
     */
    public function adopt($agentUserId, $ownerUserId, $kind): array
    {
        if (! $this->userSession->isLogged() || ! $this->userSession->isAdmin()) {
            return ['ok' => false, 'reason' => 'forbidden'];
        }
        $agentUserId = Params::id($agentUserId);
        $ownerUserId = Params::id($ownerUserId);
        $kind = is_string($kind) ? strtolower(trim($kind)) : '';
        if ($kind === '' || ! preg_match('/^[a-z0-9-]{1,32}$/', $kind)) {
            return ['ok' => false, 'reason' => 'invalid_kind'];
        }
        if ($agentUserId === 0 || $ownerUserId === 0
            || empty($this->userModel->getById($agentUserId)) || empty($this->userModel->getById($ownerUserId))) {
            return ['ok' => false, 'reason' => 'unknown_user'];
        }
        if ($agentUserId === $ownerUserId) {
            return ['ok' => false, 'reason' => 'self'];
        }
        // The roster is one level deep: an owner is never an agent, and an agent never owns agents.
        $roster = new AgentTable($this->container);
        if ($roster->getByOwner($agentUserId) !== []) {
            return ['ok' => false, 'reason' => 'agent_is_owner'];
        }
        if ($roster->isAgent($ownerUserId)) {
            return ['ok' => false, 'reason' => 'owner_is_agent'];
        }
        if (! $roster->adopt($ownerUserId, $agentUserId, $kind)) {
            return ['ok' => false, 'reason' => 'duplicate'];
        }
        return ['ok' => true, 'agent_user_id' => $agentUserId, 'owner_user_id' => $ownerUserId, 'kind' => $kind];
    }

    public function disable($agentUserId)
    {
        return $this->userModel->disable((int) $agentUserId);
    }

    public function canManage($agentUserId, $requesterUserId, $isAdmin)
    {
        if ($isAdmin) {
            return true;
        }
        $row = (new AgentTable($this->container))->getByAgent((int) $agentUserId);
        return $row !== null && (int) $row['owner_user_id'] === (int) $requesterUserId;
    }

    public function nextUsername($ownerUsername, $kind)
    {
        $base = $ownerUsername.'.'.$kind;
        if (! $this->userModel->getByUsername($base)) {
            return $base;
        }
        for ($n = 2; $n < 1000; $n++) {
            $candidate = $base.'.'.$n;
            if (! $this->userModel->getByUsername($candidate)) {
                return $candidate;
            }
        }
        throw new \RuntimeException('Too many agents named '.$base);
    }
}
