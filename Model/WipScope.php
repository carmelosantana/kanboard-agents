<?php
namespace Kanboard\Plugin\Agents\Model;

use Kanboard\Core\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Model\ProjectModel;

// Resolves the caller into whose work is shown and on which projects (spec Decisions 4, 7, 9-12).
// Core does no project scoping for plugin procedures, so this is the only gate. Never throws for
// authorization: refusals land in `denied`.
class WipScope extends Base
{
    const SCOPES = ['agents', 'mine', 'all'];

    /**
     * @param int|null   $ownerUserId whose view; null = the caller's own (an agent caller: its roster owner)
     * @param string     $scope       agents|mine|all
     * @param int[]|null $projectIds  optional narrowing; ids outside the caller's reach go to denied.project_ids
     * @return array{viewer_id:int, resolved_from:?int, caller_id:int, caller_is_agent:bool,
     *               project_ids:int[], owner_ids:int[], include_unowned:bool,
     *               agents:array<int,array>, people:array<int,array>, roster_agent_ids:int[],
     *               agent_projects:int[], denied:array{user_ids:int[], project_ids:int[]}}
     */
    public function resolve(?int $ownerUserId, string $scope, ?array $projectIds): array
    {
        $loggedIn = $this->userSession->isLogged();
        $callerId = $loggedIn ? (int) $this->userSession->getId() : 0;
        $isAdmin = $loggedIn && $this->userSession->isAdmin();
        $roster = new AgentTable($this->container);
        $callerRow = $callerId > 0 ? $roster->getByAgent($callerId) : null;

        if (! $loggedIn && $ownerUserId === null) {
            throw new \InvalidArgumentException('owner_user_id is required with the application token');
        }
        $viewerId = $ownerUserId ?? ($callerRow !== null ? (int) $callerRow['owner_user_id'] : $callerId);

        $out = [
            'viewer_id' => $viewerId,
            'resolved_from' => $callerId !== $viewerId ? $callerId : null,
            'caller_id' => $callerId,
            'caller_is_agent' => $callerRow !== null,
            'project_ids' => [],
            'owner_ids' => [],
            'include_unowned' => $scope !== 'mine',
            'agents' => [],
            'people' => [],
            'roster_agent_ids' => array_map('intval', array_column($roster->getAll(), 'agent_user_id')),
            'agent_projects' => [],
            'denied' => ['user_ids' => [], 'project_ids' => []],
        ];

        $viewer = $this->userModel->getById($viewerId);
        if (empty($viewer)) {
            return $this->deny($out, $viewerId, $projectIds);
        }

        if (! $loggedIn || $isAdmin) {
            // App token or app-admin: every active project.
            $allowed = array_map('intval', array_column($this->projectModel->getAllByStatus(ProjectModel::ACTIVE), 'id'));
        } elseif ($viewerId === $callerId) {
            $allowed = $this->active($callerId);
        } elseif ($callerRow !== null && (int) $callerRow['owner_user_id'] === $viewerId) {
            // Agent caller viewing its owner: the owner's view, intersected with the agent's own visibility.
            $allowed = array_values(array_intersect($this->active($viewerId), $this->active($callerId)));
        } else {
            // Project managers see other users only on projects they manage.
            $allowed = array_values(array_filter($this->active($callerId), fn ($pid) =>
                $this->projectUserRoleModel->getUserRole($pid, $callerId) === Role::PROJECT_MANAGER));
            if ($allowed === []) {
                return $this->deny($out, $viewerId, $projectIds);
            }
        }

        if ($projectIds !== null) {
            $out['denied']['project_ids'] = array_values(array_diff($projectIds, $allowed));
            $allowed = array_intersect($allowed, $projectIds);
        }
        sort($allowed);
        $out['project_ids'] = array_values($allowed);

        $out['people'][$viewerId] = $this->person($viewer, null);
        foreach ($roster->getByOwner($viewerId) as $row) {
            $u = $this->userModel->getById((int) $row['agent_user_id']);
            if (! empty($u)) {
                $out['agents'][(int) $u['id']] = $this->person($u, $row['kind']);
                $out['people'][(int) $u['id']] = $out['agents'][(int) $u['id']];
            }
        }
        $agentProjects = [];
        foreach (array_keys($out['agents']) as $agentId) {
            $agentProjects = array_merge($agentProjects, $this->active($agentId));
        }
        $out['agent_projects'] = array_values(array_unique($agentProjects));

        $agentIds = array_keys($out['agents']);
        $out['owner_ids'] = match ($scope) {
            'mine' => [$viewerId],
            'agents' => $agentIds,
            default => array_merge([$viewerId], $agentIds),
        };

        return $out;
    }

    private function deny(array $out, int $viewerId, ?array $projectIds): array
    {
        $out['denied']['user_ids'] = [$viewerId];
        $out['denied']['project_ids'] = $projectIds ?? [];
        return $out;
    }

    private function active(int $userId): array
    {
        return array_map('intval', $this->projectPermissionModel->getActiveProjectIds($userId));
    }

    private function person(array $u, ?string $kind): array
    {
        return [
            'username' => $u['username'],
            'name' => $u['name'] ?: $u['username'],
            'is_active' => (int) $u['is_active'],
            'kind' => $kind,
        ];
    }
}
