<?php
namespace Kanboard\Plugin\Agents\Model;

use Kanboard\Core\Base;
use Kanboard\Core\Security\Role;

// The one write path for the WIP view (Decisions 19-22), shared by the page's CSRF POST and the
// applyWipFix RPC. Guards, then moves/closes/assigns, then posts the 🧭 comment. Move provenance
// (MoveProvenanceSubscriber) records the move itself.
class WipFixService extends Base
{
    /** action => the flags that justify it */
    const ACTIONS = ['move_to_done' => ['donesubs', 'mismatch'], 'assign' => ['noowner']];

    /**
     * @return array{ok: true, task_id: int, action: string, comment_id: int}
     *       | array{ok: false, reason: 'changed'|'not_flagged'|'forbidden'|'hold'|'invalid_assignee'|'no_done_column'}
     */
    public function apply($taskId, $action, $expectedDateModification, $assigneeId = null): array
    {
        $taskId = Params::id($taskId);
        if ($taskId === 0) {
            throw new \InvalidArgumentException('task_id must be a positive id');
        }
        if (! is_string($action) || ! isset(self::ACTIONS[$action])) {
            throw new \InvalidArgumentException('action must be one of '.implode('|', array_keys(self::ACTIONS)));
        }
        if (! is_int($expectedDateModification) && ! (is_string($expectedDateModification) && ctype_digit($expectedDateModification))) {
            throw new \InvalidArgumentException('expected_date_modification must be an integer timestamp');
        }
        $assignee = $assigneeId === null ? 0 : Params::id($assigneeId);
        if ($action === 'assign' && $assignee === 0) {
            throw new \InvalidArgumentException('assign needs assignee_id');
        }

        // Writes must be attributed: the app token (no session) never fixes.
        if (! $this->userSession->isLogged()) {
            return self::no('forbidden');
        }
        $task = $this->taskFinderModel->getById($taskId);
        if (empty($task)) {
            return self::no('not_flagged');
        }
        $pid = (int) $task['project_id'];
        $scope = (new WipScope($this->container))->resolve(null, 'all', [$pid]);
        if ($scope['project_ids'] !== [$pid]) {
            return self::no('forbidden');
        }
        $owner = (int) $task['owner_id'];
        if ($owner !== 0 && ! in_array($owner, $scope['owner_ids'], true)) {
            return self::no('forbidden');
        }
        // An agent caller may fix only agent-owned or unowned tickets.
        if ($scope['caller_is_agent'] && $owner !== 0 && ! in_array($owner, $scope['roster_agent_ids'], true)) {
            return self::no('forbidden');
        }
        // Read visibility is not write permission: a project-viewer (or non-member) never fixes.
        if (! $this->mayWrite($pid)) {
            return self::no('forbidden');
        }
        if ((int) $task['date_modification'] !== (int) $expectedDateModification) {
            return self::no('changed');
        }

        $view = new WipView($this->container);
        $cat = new WipCatalogue();
        $now = time();
        // The lookback window is a display filter, not a guard: re-evaluate over the widest window
        // getWipFlags accepts, so any row a caller was shown can be fixed.
        $lookback = WipView::MAX_LOOKBACK_DAYS;
        $columns = (new WipQuery($this->container))->columns([$pid]);
        $facts = $view->facts($scope, $columns, $now - $lookback * 86400, $taskId);
        $fact = $facts[0] ?? null;

        if ($action === 'move_to_done' && $fact !== null && self::heldFromDone($fact['tags'])) {
            return self::no('hold');
        }
        $flags = $fact === null ? [] : WipFlagRules::evaluate($fact, $cat, $now, $lookback, $cat->available())['flags'];
        $flag = array_values(array_intersect(self::ACTIONS[$action], $flags))[0] ?? null;
        if ($flag === null) {
            return self::no('not_flagged');
        }

        if ($action === 'assign') {
            $candidates = $this->assignees($pid, $scope);
            if (! isset($candidates[$assignee])) {
                return self::no('invalid_assignee');
            }
            if (! $this->helper->projectRole->canChangeAssignee($task)) {
                return self::no('forbidden');
            }
            $this->taskModificationModel->update(['id' => $taskId, 'owner_id' => $assignee]);
            $label = 'Assign to '.$candidates[$assignee];
        } else {
            if ($columns[$pid]['done'] === []) {
                return self::no('no_done_column');
            }
            $done = $columns[$pid]['done'][0];
            // Custom project roles: core's own move/close restriction checks, as its controllers use them.
            if (! $this->helper->projectRole->canMoveTask($pid, (int) $task['column_id'], $done)
                || ($flag === 'donesubs' && ! $this->helper->projectRole->canChangeTaskStatusInColumn($pid, $done))) {
                return self::no('forbidden');
            }
            $swimlane = (int) $task['swimlane_id'];
            if ((int) $task['column_id'] !== $done) {
                $position = $this->taskFinderModel->countByColumnAndSwimlaneId($pid, $done, $swimlane) + 1;
                // onlyOpen=false: a Closed-not-in-Done ticket moves and stays closed.
                $this->taskPositionModel->movePosition($pid, $taskId, $done, $position, $swimlane, true, false);
            }
            if ($flag === 'donesubs') {
                $this->taskStatusModel->close($taskId);
            }
            $label = 'Move to Done';
        }

        $commentId = $this->commentModel->create([
            'task_id' => $taskId,
            'user_id' => (int) $this->userSession->getId(),
            'comment' => self::comment($label, $cat->flag($flag)['label'], $this->userSession->getUsername()),
        ]);

        return ['ok' => true, 'task_id' => $taskId, 'action' => $action, 'comment_id' => (int) $commentId];
    }

    /** Tickets tagged `hold` or `wayfinder:*` are never moved to Done. */
    public static function heldFromDone(array $tags): bool
    {
        return in_array('hold', $tags, true) || WipFlagRules::hasWayfinder($tags);
    }

    /**
     * Can the one-click fix for a row's top flag succeed? The single guard behind a row's
     * fix.oneclick (WipView) and the refusals apply() would otherwise return for it.
     */
    public static function oneClickable(?string $flag, array $tags, bool $hasAssignee, WipCatalogue $cat): bool
    {
        if ($flag === null || ! $cat->flag($flag)['oneclick']) {
            return false;
        }
        foreach (self::ACTIONS as $action => $flags) {
            if (in_array($flag, $flags, true)) {
                return $action === 'assign' ? $hasAssignee : ! self::heldFromDone($tags);
            }
        }
        return false;
    }

    /**
     * Who a No-owner ticket on $projectId may be assigned to: the viewer or one of their Roster
     * agents, active and assignable on that project. @return array<int, string> uid => username
     */
    public function assignees(int $projectId, array $scope): array
    {
        $out = [];
        foreach ($scope['people'] as $uid => $person) {
            if ($this->projectPermissionModel->isAssignable($projectId, $uid)) {
                $out[$uid] = $person['username'];
            }
        }
        return $out;
    }

    private function mayWrite(int $projectId): bool
    {
        if ($this->userSession->isAdmin()) {
            return true;
        }
        $role = $this->projectUserRoleModel->getUserRole($projectId, (int) $this->userSession->getId());
        return $role !== '' && $role !== Role::PROJECT_VIEWER;
    }

    public static function comment(string $action, string $flagLabel, string $user): string
    {
        return '🧭 WIP view: '.$action.' ('.$flagLabel.') by '.$user;
    }

    private static function no(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason];
    }
}
