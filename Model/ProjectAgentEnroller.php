<?php
namespace Kanboard\Plugin\Agents\Model;

use Kanboard\Core\Base;
use Kanboard\Core\Security\Role;

/** Adds every active roster agent to a project in one go (Project → Permissions → Add all agents). */
class ProjectAgentEnroller extends Base
{
    const ROLES = [Role::PROJECT_MEMBER, Role::PROJECT_MANAGER];

    public static function roleLabel(string $role): string
    {
        return $role === Role::PROJECT_MANAGER ? t('Manager') : t('Member');
    }

    /**
     * Agents with any role on the project (direct or via a group) are left as they are.
     *
     * @return array{added:int,existing:int}|null null when the role is not member/manager
     */
    public function addAll(int $projectId, string $role): ?array
    {
        if (! in_array($role, self::ROLES, true)) {
            return null;
        }

        $added = 0;
        $existing = 0;
        foreach ((new AgentTable($this->container))->getAll() as $row) {
            $userId = (int) $row['agent_user_id'];
            $user = $this->userModel->getById($userId);
            if (empty($user) || (int) $user['is_active'] !== 1) {
                continue;
            }
            if ($this->projectUserRoleModel->getUserRole($projectId, $userId) !== '') {
                $existing++;
                continue;
            }
            if ($this->projectUserRoleModel->addUser($projectId, $userId, $role)) {
                $added++;
            }
        }

        return ['added' => $added, 'existing' => $existing];
    }
}
