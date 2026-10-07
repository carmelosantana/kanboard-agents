<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/WipFixture.php';

use KanboardTests\units\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Model\ProjectRoleRestrictionModel;
use Kanboard\Plugin\Agents\Api\AgentsWipProcedure;
use Kanboard\Plugin\Agents\Model\WipCatalogue;
use Kanboard\Plugin\Agents\Model\WipFixService;
use Kanboard\Plugin\Agents\Model\WipQuery;
use Kanboard\Plugin\Agents\Model\WipScope;
use Kanboard\Plugin\Agents\Model\WipView;
use Kanboard\Plugin\Agents\Subscriber\MoveProvenanceSubscriber;

class WipFixServiceTest extends Base
{
    use WipFixture;

    private int $carmelo; private int $claude; private int $pid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAgents();
        $this->carmelo = $this->user('carmelo');
        $this->claude = $this->agentOf($this->carmelo, 'carmelo.claude');
        $this->pid = $this->project('P', [$this->carmelo => Role::PROJECT_MEMBER, $this->claude => Role::PROJECT_MEMBER]);
        (new MoveProvenanceSubscriber($this->container))->register();
        $this->actAs($this->carmelo);
    }

    private function fix(int $tid, string $action, ?int $assignee = null, ?int $expected = null): array
    {
        $expected = $expected ?? (int) $this->container['taskFinderModel']->getById($tid)['date_modification'];
        return (new AgentsWipProcedure($this->container))->applyWipFix($tid, $action, $expected, $assignee);
    }

    private function closedOutsideDone(int $owner): int
    {
        $t = $this->task($this->pid, 'In progress', $owner);
        $this->touch($t, ['is_active' => 0, 'date_completed' => time() - 86400]);
        return $t;
    }

    private function allSubtasksDone(int $owner): int
    {
        $t = $this->task($this->pid, 'In progress', $owner);
        $this->subtask($t, 'a', 2);
        $this->subtask($t, 'b', 2);
        return $t;
    }

    private function task_(int $tid): array
    {
        return $this->container['taskFinderModel']->getById($tid);
    }

    private function lastComment(int $tid): string
    {
        $all = $this->container['commentModel']->getAll($tid);
        return end($all)['comment'];
    }

    public function testMismatchMovesToDoneAndStaysClosed(): void
    {
        $t = $this->closedOutsideDone($this->carmelo);
        $r = $this->fix($t, 'move_to_done');
        $this->assertTrue($r['ok']);
        $this->assertSame(['ok', 'task_id', 'action', 'comment_id'], array_keys($r));
        $task = $this->task_($t);
        $this->assertSame($this->col($this->pid, 'Done'), (int) $task['column_id']);
        $this->assertSame(0, (int) $task['is_active']);
        $this->assertSame('🧭 WIP view: Move to Done (Closed, not in Done) by carmelo', $this->lastComment($t));
        $this->assertSame('human', $this->container['taskMetadataModel']->get($t, 'moved_by_kind'));
    }

    public function testDonesubsMovesToDoneAndCloses(): void
    {
        $t = $this->allSubtasksDone($this->claude);
        $this->assertTrue($this->fix($t, 'move_to_done')['ok']);
        $task = $this->task_($t);
        $this->assertSame($this->col($this->pid, 'Done'), (int) $task['column_id']);
        $this->assertSame(0, (int) $task['is_active']);
        $this->assertSame('🧭 WIP view: Move to Done (All subtasks done) by carmelo', $this->lastComment($t));
    }

    public function testDonesubsAlreadyInDoneJustCloses(): void
    {
        $t = $this->task($this->pid, 'Done', $this->carmelo);
        $this->subtask($t, 'a', 2);
        $this->assertTrue($this->fix($t, 'move_to_done')['ok']);
        $this->assertSame(0, (int) $this->task_($t)['is_active']);
        $this->assertSame($this->col($this->pid, 'Done'), (int) $this->task_($t)['column_id']);
    }

    public function testNoDoneColumnIsRefusedNotCrashed(): void
    {
        $this->container['columnModel']->update($this->col($this->pid, 'Done'), 'Shipped');
        $t = $this->allSubtasksDone($this->carmelo);
        $this->assertSame(['ok' => false, 'reason' => 'no_done_column'], $this->fix($t, 'move_to_done'));
        $this->assertSame(1, (int) $this->task_($t)['is_active']);
    }

    public function testDoubleSubmitIsRefusedNotAppliedTwice(): void
    {
        $t = $this->closedOutsideDone($this->carmelo);
        $expected = (int) $this->task_($t)['date_modification'];
        $this->assertTrue($this->fix($t, 'move_to_done', null, $expected)['ok']);
        // Same form posted again: either the timestamp moved (changed) or the flag is gone (not_flagged).
        $again = $this->fix($t, 'move_to_done', null, $expected);
        $this->assertFalse($again['ok']);
        $this->assertContains($again['reason'], ['changed', 'not_flagged']);
        $this->assertCount(1, $this->container['commentModel']->getAll($t));
    }

    public function testAssignSetsOwnerToARosterAgent(): void
    {
        $t = $this->task($this->pid, 'In progress');
        $r = $this->fix($t, 'assign', $this->claude);
        $this->assertTrue($r['ok']);
        $this->assertSame($this->claude, (int) $this->task_($t)['owner_id']);
        $this->assertSame('🧭 WIP view: Assign to carmelo.claude (No owner) by carmelo', $this->lastComment($t));
    }

    public function testAssignRefusesSomeoneOutsideTheRoster(): void
    {
        $stranger = $this->user('stranger');
        $this->container['projectUserRoleModel']->addUser($this->pid, $stranger, Role::PROJECT_MEMBER);
        $t = $this->task($this->pid, 'In progress');
        $this->assertSame(['ok' => false, 'reason' => 'invalid_assignee'], $this->fix($t, 'assign', $stranger));
        $this->assertSame(0, (int) $this->task_($t)['owner_id']);
    }

    public function testAssignRefusesARosterAgentWhoIsNotAMember(): void
    {
        $codex = $this->agentOf($this->carmelo, 'carmelo.codex', 'codex');
        $t = $this->task($this->pid, 'In progress');
        $this->assertSame('invalid_assignee', $this->fix($t, 'assign', $codex)['reason']);
    }

    public function testChangedWhenDateModificationMoved(): void
    {
        $t = $this->closedOutsideDone($this->carmelo);
        $stale = (int) $this->task_($t)['date_modification'] - 5;
        $this->assertSame(['ok' => false, 'reason' => 'changed'], $this->fix($t, 'move_to_done', null, $stale));
    }

    public function testNotFlaggedWhenTheFlagNoLongerHolds(): void
    {
        $t = $this->task($this->pid, 'In progress', $this->carmelo);
        $this->subtask($t, 'a', 2);
        $this->subtask($t, 'b', 0);
        $this->assertSame(['ok' => false, 'reason' => 'not_flagged'], $this->fix($t, 'move_to_done'));
        $this->assertSame('not_flagged', $this->fix($this->task($this->pid, 'In progress', $this->carmelo), 'assign', $this->claude)['reason']);
    }

    public function testLookbackIsNotAGuardForOlderClosedTickets(): void
    {
        // A getWipFlags caller with closed_lookback_days=60 sees this row; the fix must not refuse it.
        $t = $this->task($this->pid, 'In progress', $this->carmelo);
        $this->touch($t, ['is_active' => 0, 'date_completed' => time() - 60 * 86400]);
        $this->assertTrue($this->fix($t, 'move_to_done')['ok']);
        $this->assertSame($this->col($this->pid, 'Done'), (int) $this->task_($t)['column_id']);
    }

    public function testHoldAndWayfinderAreNeverMovedToDone(): void
    {
        $hold = $this->closedOutsideDone($this->carmelo);
        $this->tags($this->pid, $hold, ['hold']);
        $this->assertSame(['ok' => false, 'reason' => 'hold'], $this->fix($hold, 'move_to_done'));
        $map = $this->closedOutsideDone($this->carmelo);
        $this->tags($this->pid, $map, ['wayfinder:map']);
        $this->assertSame('hold', $this->fix($map, 'move_to_done')['reason']);
        $this->assertNotSame($this->col($this->pid, 'Done'), (int) $this->task_($map)['column_id']);
    }

    public function testAppTokenIsRefused(): void
    {
        $t = $this->closedOutsideDone($this->carmelo);
        $this->actAsAppToken();
        $this->assertSame(['ok' => false, 'reason' => 'forbidden'], $this->fix($t, 'move_to_done'));
    }

    public function testOtherUsersTicketIsForbidden(): void
    {
        $stranger = $this->user('stranger');
        $t = $this->closedOutsideDone($stranger);
        $this->assertSame('forbidden', $this->fix($t, 'move_to_done')['reason']);
    }

    public function testProjectOutsideReachIsForbidden(): void
    {
        $hidden = $this->project('Hidden');
        $t = $this->task($hidden, 'In progress', $this->carmelo);
        $this->touch($t, ['is_active' => 0, 'date_completed' => time()]);
        $this->assertSame('forbidden', $this->fix($t, 'move_to_done')['reason']);
    }

    public function testProjectViewerMayNotWrite(): void
    {
        $t = $this->closedOutsideDone($this->carmelo);
        $this->container['projectUserRoleModel']->changeUserRole($this->pid, $this->carmelo, Role::PROJECT_VIEWER);
        $this->assertSame(['ok' => false, 'reason' => 'forbidden'], $this->fix($t, 'move_to_done'));
        $this->assertNotSame($this->col($this->pid, 'Done'), (int) $this->task_($t)['column_id']);
        $this->assertSame([], $this->container['commentModel']->getAll($t));
    }

    public function testCustomRoleMoveRestrictionIsHonoured(): void
    {
        $t = $this->closedOutsideDone($this->carmelo);
        $roleId = $this->container['projectRoleModel']->create($this->pid, 'no-move');
        $this->container['projectRoleRestrictionModel']->create($this->pid, $roleId, ProjectRoleRestrictionModel::RULE_TASK_MOVE);
        $this->container['projectUserRoleModel']->changeUserRole($this->pid, $this->carmelo, 'no-move');
        $this->assertSame(['ok' => false, 'reason' => 'forbidden'], $this->fix($t, 'move_to_done'));
        $this->assertNotSame($this->col($this->pid, 'Done'), (int) $this->task_($t)['column_id']);
        $this->assertSame([], $this->container['commentModel']->getAll($t));
    }

    public function testCustomRoleAssigneeRestrictionIsHonoured(): void
    {
        $t = $this->task($this->pid, 'In progress');
        $roleId = $this->container['projectRoleModel']->create($this->pid, 'no-assign');
        $this->container['projectRoleRestrictionModel']->create($this->pid, $roleId, ProjectRoleRestrictionModel::RULE_TASK_CHANGE_ASSIGNEE);
        $this->container['projectUserRoleModel']->changeUserRole($this->pid, $this->carmelo, 'no-assign');
        $this->assertSame(['ok' => false, 'reason' => 'forbidden'], $this->fix($t, 'assign', $this->claude));
        $this->assertSame(0, (int) $this->task_($t)['owner_id']);
    }

    public function testAdminWhoIsNotAMemberMayFix(): void
    {
        $admin = $this->user('boss', Role::APP_ADMIN);
        $t = $this->closedOutsideDone($admin);
        $this->actAs($admin);
        $this->assertTrue($this->fix($t, 'move_to_done')['ok']);
    }

    public function testAgentCallerMayNotFixAHumansTicket(): void
    {
        $human = $this->closedOutsideDone($this->carmelo);
        $agents = $this->closedOutsideDone($this->claude);
        $this->actAs($this->claude);
        $this->assertSame('forbidden', $this->fix($human, 'move_to_done')['reason']);
        $this->assertTrue($this->fix($agents, 'move_to_done')['ok']);
        $this->assertSame('🧭 WIP view: Move to Done (Closed, not in Done) by carmelo.claude', $this->lastComment($agents));
    }

    // The caller's scope resolves once per request: a pre-resolved scope seeds the memo and later calls reuse it.
    public function testCallerAssigneesSeedsAndReusesThePassedScope(): void
    {
        $scope = (new WipScope($this->container))->resolve(null, 'all', null);
        unset($scope['people'][$this->carmelo]); // a marker: a fresh resolve would include carmelo
        $fixer = new WipFixService($this->container);
        $this->assertSame([$this->claude => 'carmelo.claude'], $fixer->callerAssignees($this->pid, $scope));
        $this->assertSame([$this->claude => 'carmelo.claude'], $fixer->callerAssignees($this->pid));
    }

    public function testOneClickableUsesTheSeededCallerScope(): void
    {
        $t = $this->closedOutsideDone($this->carmelo);
        $columns = (new WipQuery($this->container))->columns([$this->pid]);
        $fact = WipView::fact($this->task_($t) + ['project_name' => 'P', 'last_comment' => null, 'sub_total' => 0, 'sub_done' => 0, 'sub_prog' => 0],
            $columns, ['roster_agent_ids' => [$this->claude], 'agent_projects' => [$this->pid]], [], []);
        $fixer = new WipFixService($this->container);
        $this->assertTrue($fixer->oneClickable('mismatch', $fact, false, new WipCatalogue(), $columns));
        $scope = (new WipScope($this->container))->resolve(null, 'all', null);
        $scope['owner_ids'] = []; // a marker: carmelo's own row is out of this seeded scope
        $seeded = new WipFixService($this->container);
        $seeded->seedCallerScope($scope);
        $this->assertFalse($seeded->oneClickable('mismatch', $fact, false, new WipCatalogue(), $columns));
    }

    public function testMalformedInputThrowsInvalidArgument(): void
    {
        $t = $this->closedOutsideDone($this->carmelo);
        $rpc = new AgentsWipProcedure($this->container);
        foreach ([[0, 'move_to_done', 1], [$t, 'delete', 1], [$t, 'move_to_done', 'soon'], [$t, 'assign', 1, null]] as $args) {
            try {
                $rpc->applyWipFix(...$args);
                $this->fail('accepted '.json_encode($args));
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
