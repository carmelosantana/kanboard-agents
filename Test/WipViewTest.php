<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/WipFixture.php';

use KanboardTests\units\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Agents\Api\AgentsWipProcedure;
use Kanboard\Plugin\Agents\Model\WipCatalogue;
use Kanboard\Plugin\Agents\Model\WipFlagRules;
use Kanboard\Plugin\Agents\Model\WipScope;
use Kanboard\Plugin\Agents\Model\WipView;

// getWipFlags end to end on SQLite: envelope, rows, ranking, counts, early return.
class WipViewTest extends Base
{
    use WipFixture;

    const DAY = 86400;

    private int $carmelo; private int $claude; private int $pid; private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAgents();
        $this->now = time();
        $this->carmelo = $this->user('carmelo');
        $this->claude = $this->agentOf($this->carmelo, 'carmelo.claude');
        $this->pid = $this->project('P', [$this->carmelo => Role::PROJECT_MEMBER, $this->claude => Role::PROJECT_MEMBER]);
        $this->actAs($this->carmelo);
    }

    private function rpc(...$args): array
    {
        return (new AgentsWipProcedure($this->container))->getWipFlags(...$args);
    }

    private function stale(int $tid): void
    {
        $old = $this->now - 10 * self::DAY;
        $this->touch($tid, ['date_moved' => $old, 'date_modification' => $old]);
    }

    public function testEnvelopeShape(): void
    {
        $env = $this->rpc();
        $this->assertSame(['catalogue_version', 'generated_at', 'viewer', 'scope', 'summary', 'unavailable_flags', 'unmapped_projects', 'rows', 'denied'], array_keys($env));
        $this->assertSame((new WipCatalogue())->version(), $env['catalogue_version']);
        $this->assertStringStartsWith('sha256:', $env['catalogue_version']);
        $this->assertSame(['user_id' => $this->carmelo, 'resolved_from' => null], $env['viewer']);
        $this->assertSame('all', $env['scope']);
        $this->assertSame(['merged', 'ended', 'unverified', 'timemiss'], $env['unavailable_flags']);
    }

    public function testUnavailableFlagCountsAreNullOthersZero(): void
    {
        $s = $this->rpc()['summary'];
        foreach (['merged', 'ended', 'unverified', 'timemiss'] as $k) {
            $this->assertNull($s[$k], $k);
        }
        foreach (['in_progress', 'flagged', 'stale', 'donesubs', 'offboard', 'mismatch', 'blocked', 'noowner'] as $k) {
            $this->assertSame(0, $s[$k], $k);
        }
    }

    public function testRowsAreRankedAndCarryFactsNotProse(): void
    {
        $blocked = $this->task($this->pid, 'In progress', $this->carmelo, 'blocked one');
        $this->tags($this->pid, $blocked, ['blocked']);
        $stale = $this->task($this->pid, 'In progress', $this->claude, 'stale one');
        $this->stale($stale);
        $env = $this->rpc();
        $this->assertSame([$stale, $blocked], array_column($env['rows'], 'task_id'));
        $row = $env['rows'][0];
        $this->assertSame(['stale'], $row['flags']);
        $this->assertSame(5, $row['rank_weight']);
        $this->assertSame('agent', $row['owner_kind']);
        $this->assertSame('carmelo.claude', $row['owner_name']);
        $this->assertFalse($row['owner_disabled']);
        $this->assertSame(['action' => 'stale', 'oneclick' => false, 'url' => '/task/'.$stale, 'location' => null], $row['fix']);
        $this->assertSame('In progress', $row['column']);
        $this->assertArrayNotHasKey('sort_ts', $row);
        $this->assertSame(2, $env['summary']['in_progress']);
        $this->assertSame(2, $env['summary']['flagged']);
        $this->assertSame(1, $env['summary']['stale']);
        $this->assertSame(1, $env['summary']['blocked']);
        $this->assertSame('human', $env['rows'][1]['owner_kind']);
    }

    public function testDuplicateRosterRowDoesNotDuplicateTickets(): void
    {
        // agents has no uniqueness on agent_user_id; a hand-inserted duplicate must not double rows.
        (new \Kanboard\Plugin\Agents\Model\AgentTable($this->container))->insert($this->carmelo, $this->claude, 'claude');
        $t = $this->task($this->pid, 'In progress', $this->claude);
        $this->stale($t);
        $env = $this->rpc();
        $this->assertSame([$t], array_column($env['rows'], 'task_id'));
        $this->assertSame(1, $env['summary']['stale']);
    }

    public function testOtherUsersTicketsAreOutOfView(): void
    {
        $stranger = $this->user('stranger');
        $t = $this->task($this->pid, 'In progress', $stranger);
        $this->stale($t);
        $this->assertSame([], $this->rpc()['rows']);
    }

    public function testUnownedInProgressCountsOnlyWhereAnAgentIsMember(): void
    {
        $t = $this->task($this->pid, 'In progress');
        $agentless = $this->project('Q', [$this->carmelo => Role::PROJECT_MEMBER]);
        $this->task($agentless, 'In progress');
        $rows = $this->rpc()['rows'];
        $this->assertSame([$t], array_column($rows, 'task_id'));
        $this->assertSame(['noowner'], $rows[0]['flags']);
        $this->assertSame(['action' => 'noowner', 'oneclick' => true, 'url' => '/task/'.$t, 'location' => null], $rows[0]['fix']);
    }

    public function testMoveToDoneIsNotOneClickOnHoldOrWayfinderTickets(): void
    {
        $held = $this->task($this->pid, 'In progress', $this->carmelo, 'held');
        $this->touch($held, ['is_active' => 0, 'date_completed' => $this->now - self::DAY]);
        $this->tags($this->pid, $held, ['hold']);
        $map = $this->task($this->pid, 'In progress', $this->carmelo, 'map');
        $this->subtask($map, 'a', 2);
        $this->tags($this->pid, $map, ['wayfinder:map']);
        $rows = array_column($this->rpc()['rows'], 'fix', 'task_id');
        $this->assertSame(['mismatch', false], [$rows[$held]['action'], $rows[$held]['oneclick']]);
        $this->assertSame(['donesubs', false], [$rows[$map]['action'], $rows[$map]['oneclick']]);
    }

    public function testNoOwnerIsNotOneClickWhenNobodyIsAssignable(): void
    {
        $q = $this->project('Q', [$this->carmelo => Role::PROJECT_VIEWER, $this->claude => Role::PROJECT_VIEWER]);
        $t = $this->task($q, 'In progress');
        $rows = array_column($this->rpc()['rows'], 'fix', 'task_id');
        $this->assertSame(['noowner', false], [$rows[$t]['action'], $rows[$t]['oneclick']]);
    }

    public function testDisabledAgentKeepsItsTicketsWithAMarker(): void
    {
        $t = $this->task($this->pid, 'In progress', $this->claude);
        $this->stale($t);
        $this->container['userModel']->disable($this->claude);
        $this->assertTrue($this->rpc()['rows'][0]['owner_disabled']);
    }

    public function testIncludeUnflaggedAddsOnlyOpenInProgressRows(): void
    {
        $plain = $this->task($this->pid, 'In progress', $this->carmelo);
        $this->task($this->pid, 'Backlog', $this->carmelo);
        $this->task($this->pid, 'Ready', $this->claude);
        $this->assertSame([], $this->rpc()['rows']);
        $rows = $this->rpc(null, "all", null, true)["rows"];
        $this->assertSame([$plain], array_column($rows, 'task_id'));
        $this->assertSame(0, $rows[0]['rank_weight']);
        $this->assertNull($rows[0]['fix']['action']);
    }

    public function testMismatchUsesTheLookbackParameter(): void
    {
        $t = $this->task($this->pid, 'In progress', $this->carmelo);
        $this->touch($t, ['is_active' => 0, 'date_completed' => $this->now - 40 * self::DAY]);
        $this->assertSame([], $this->rpc()['rows']);
        $this->assertSame([['mismatch']], array_column($this->rpc(null, 'all', null, false, 60)['rows'], 'flags'));
    }

    public function testEmptyProjectSetReturnsEarlyWithNoRows(): void
    {
        // A user with no projects must not see the instance (PicoDb in([]) would return every task).
        $this->stale($this->task($this->pid, 'In progress', $this->carmelo));
        $loner = $this->user('loner');
        $this->actAs($loner);
        $env = $this->rpc();
        $this->assertSame([], $env['rows']);
        $this->assertSame(0, $env['summary']['flagged']);
    }

    public function testDeniedViewIsEmptyNotAnException(): void
    {
        $this->stale($this->task($this->pid, 'In progress', $this->carmelo));
        $other = $this->user('other');
        $this->actAs($other);
        $env = $this->rpc($this->carmelo);
        $this->assertSame([], $env['rows']);
        $this->assertSame(['user_ids' => [$this->carmelo], 'project_ids' => []], $env['denied']);
    }

    public function testUnmappedProjectsAreReported(): void
    {
        $this->container['columnModel']->update($this->col($this->pid, 'Done'), 'Shipped');
        $this->assertSame([['project_id' => $this->pid, 'missing' => ['Done']]], $this->rpc()['unmapped_projects']);
    }

    public function testAppTokenReadsWithOwnerAndIsRefusedWithout(): void
    {
        $this->stale($this->task($this->pid, 'In progress', $this->carmelo));
        $this->actAsAppToken();
        $this->assertCount(1, $this->rpc($this->carmelo)['rows']);
        $this->expectException(\InvalidArgumentException::class);
        $this->rpc();
    }

    public function testMalformedParamsThrowInvalidArgument(): void
    {
        foreach ([[0], [null, 'everything'], [null, 'all', 'P1'], [null, 'all', null, 'yes'], [null, 'all', null, false, 0], ['abc']] as $args) {
            try {
                $this->rpc(...$args);
                $this->fail('accepted '.json_encode($args));
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // Ruling 1: MariaDB returns COUNT/SUM/MAX as strings; WipFlagRules compares sub_done === sub_total.
    public function testStringCountsFromTheDriverStillFlag(): void
    {
        $columns = [$this->pid => ['in_progress' => [7], 'ready' => [], 'done' => [9], 'titles' => [7 => 'In progress', 9 => 'Done']]];
        $scope = ['roster_agent_ids' => [$this->claude], 'agent_projects' => [$this->pid]];
        $raw = [
            'id' => '42', 'title' => 'T', 'project_id' => (string) $this->pid, 'project_name' => 'P', 'column_id' => '7',
            'owner_id' => (string) $this->claude, 'is_active' => '1', 'date_moved' => (string) $this->now,
            'date_modification' => (string) $this->now, 'date_completed' => '0',
            'sub_total' => '3', 'sub_done' => '3', 'sub_prog' => '0', 'last_comment' => (string) ($this->now - 5),
        ];
        $f = WipView::fact($raw, $columns, $scope, [], []);
        foreach (['task_id', 'project_id', 'owner_id', 'is_active', 'date_moved', 'date_modification', 'date_completed', 'sub_total', 'sub_done', 'sub_prog', 'last_comment'] as $k) {
            $this->assertIsInt($f[$k], $k);
        }
        $this->assertSame('agent', $f['owner_kind']);
        $this->assertTrue($f['agent_member']);
        $this->assertNull(WipView::fact(['last_comment' => null] + $raw, $columns, $scope, [], [])['last_comment']);
        $cat = new WipCatalogue();
        $this->assertContains('donesubs', WipFlagRules::evaluate($f, $cat, $this->now, 30, $cat->available())['flags']);
    }

    public function testDoneSubtasksFlagEndToEnd(): void
    {
        $t = $this->task($this->pid, 'In progress', $this->claude);
        $this->subtask($t, 'a', 2);
        $this->subtask($t, 'b', 2);
        $rows = $this->rpc()['rows'];
        $this->assertSame([$t], array_column($rows, 'task_id'));
        $this->assertContains('donesubs', $rows[0]['flags']);
        $this->assertSame(2, $rows[0]['evidence']['subtasks_done']);
    }

    // Ruling 2: WipScope treats an unknown scope as the widest view; WipView must refuse it.
    public function testUnknownScopeIsRejectedByWipViewItself(): void
    {
        foreach (['everything', 'ALL', '', 1, null, ['all']] as $scope) {
            try {
                (new WipView($this->container))->flags(null, $scope);
                $this->fail('accepted scope '.json_encode($scope));
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (WipScope::SCOPES as $scope) {
            $this->assertSame($scope, (new WipView($this->container))->flags(null, $scope)['scope']);
        }
    }

    // Ruling 4: a project manager viewing another user gets agent_projects narrowed to project_ids.
    public function testManagerViewNarrowsAgentProjectsToVisibleProjects(): void
    {
        $pm = $this->user('pm');
        $managed = $this->project('M', [$pm => Role::PROJECT_MANAGER, $this->carmelo => Role::PROJECT_MEMBER, $this->claude => Role::PROJECT_MEMBER]);
        $this->actAs($pm);
        [$env, $s] = (new WipView($this->container))->build($this->carmelo);
        $this->assertSame([$managed], $s['project_ids']);
        $this->assertSame([$managed], $s['agent_projects']);
        $t = $this->task($managed, 'In progress');
        $hidden = $this->task($this->pid, 'In progress');
        $this->assertSame([$t], array_column((new WipView($this->container))->flags($this->carmelo)['rows'], 'task_id'));
    }
}
