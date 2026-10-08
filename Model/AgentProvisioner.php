<?php
namespace Kanboard\Plugin\Agents\Model;

use Kanboard\Core\Base;
use Kanboard\Core\Security\Token;
use Kanboard\Core\Security\Role;

class AgentProvisioner extends Base
{
    /**
     * Create an API-only agent user owned by $ownerUserId. Returns username/token/agent id.
     * Atomic (#6072): all three writes commit together or nothing is left, and any failure throws.
     */
    public function create($ownerUserId, $kind, $label = '')
    {
        $owner = $this->userModel->getById($ownerUserId);
        if (empty($owner)) {
            throw new \LogicException('Unknown owner user '.$ownerUserId);
        }

        $username = $this->nextUsername($owner['username'], $kind);
        $name = $label !== '' ? $label : ($owner['username']."'s ".$kind);

        // Only commit a transaction this call opened; inside a caller's, leave the commit to the caller.
        $owned = ! $this->db->getConnection()->inTransaction();
        $this->db->startTransaction();
        try {
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
            if (! $this->db->table(\Kanboard\Model\UserModel::TABLE)->eq('id', $agentId)->update(['api_access_token' => $token])) {
                throw new \RuntimeException('Failed to set the API token of agent user '.$username);
            }

            // Owner-group is deferred from MVP (no consumer yet); the agents table is the roster.
            if (! (new AgentTable($this->container))->insert($ownerUserId, $agentId, $kind)) {
                throw new \RuntimeException('Failed to add agent user '.$username.' to the roster');
            }

            if ($owned) {
                $this->db->closeTransaction();
            }
        } catch (\Throwable $e) {
            // PicoDb may already have rolled back on an SQL error; cancelling again is then a no-op.
            $this->db->cancelTransaction();
            throw $e;
        }

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
        // Moves this user made before adoption were stamped human: they are agent moves now.
        // Best effort: the adoption stands even if the relabel fails.
        try {
            ProvenanceRelabel::run($this->db->getConnection(), $agentUserId);
        } catch (\Throwable $e) {
            $this->logger->error('Agents adopt relabel: '.$e->getMessage());
        }
        return ['ok' => true, 'agent_user_id' => $agentUserId, 'owner_user_id' => $ownerUserId, 'kind' => $kind];
    }

    /** Admin-only API create (#4568). The token is returned once and is not readable anywhere afterwards. */
    public function createForApi($ownerUserId, $kind, $label): array
    {
        if (! $this->userSession->isLogged() || ! $this->userSession->isAdmin()) {
            return ['ok' => false, 'reason' => 'forbidden'];
        }
        $ownerUserId = Params::id($ownerUserId);
        $kind = is_string($kind) ? strtolower(trim($kind)) : '';
        if ($kind === '' || ! preg_match('/^[a-z0-9-]{1,32}$/', $kind)) {
            return ['ok' => false, 'reason' => 'invalid_kind'];
        }
        if ($ownerUserId === 0 || empty($this->userModel->getById($ownerUserId))) {
            return ['ok' => false, 'reason' => 'unknown_user'];
        }
        if ((new AgentTable($this->container))->isAgent($ownerUserId)) {
            return ['ok' => false, 'reason' => 'owner_is_agent'];
        }
        try {
            return ['ok' => true] + $this->create($ownerUserId, $kind, is_string($label) ? trim($label) : '');
        } catch (\Throwable $e) {
            $this->logger->error('Agents createAgent: '.$e->getMessage());
            return ['ok' => false, 'reason' => 'create_failed'];
        }
    }

    /** Admin-only roster listing; 0 = every owner. No credential column is ever read. */
    public function listForApi($ownerUserId): array
    {
        if (! $this->userSession->isLogged() || ! $this->userSession->isAdmin()) {
            return ['ok' => false, 'reason' => 'forbidden'];
        }
        $ownerUserId = Params::id($ownerUserId);
        $roster = new AgentTable($this->container);
        $rows = $ownerUserId === 0 ? $roster->getAll() : $roster->getByOwner($ownerUserId);
        $agents = [];
        foreach ($rows as $row) {
            $u = $this->userModel->getById((int) $row['agent_user_id']);
            $agents[] = [
                'agent_user_id' => (int) $row['agent_user_id'],
                'username' => $u['username'] ?? '',
                'name' => $u['name'] ?? '',
                'owner_user_id' => (int) $row['owner_user_id'],
                'kind' => $row['kind'],
                'is_active' => (int) ($u['is_active'] ?? 0),
            ];
        }
        return ['ok' => true, 'agents' => $agents];
    }

    /** Admin-only disable, limited to roster agents so it can never lock out a human. */
    public function disableForApi($agentUserId): array
    {
        if (! $this->userSession->isLogged() || ! $this->userSession->isAdmin()) {
            return ['ok' => false, 'reason' => 'forbidden'];
        }
        $agentUserId = Params::id($agentUserId);
        if ($agentUserId === 0 || ! (new AgentTable($this->container))->isAgent($agentUserId)) {
            return ['ok' => false, 'reason' => 'not_agent'];
        }
        $this->disable($agentUserId);
        return ['ok' => true, 'agent_user_id' => $agentUserId];
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
