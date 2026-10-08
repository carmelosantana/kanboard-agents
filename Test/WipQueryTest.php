<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/WipFixture.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Agents\Model\WipQuery;

class WipQueryTest extends Base
{
    use WipFixture;

    private int $pid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAgents();
        $this->pid = $this->project('P');
    }

    private function q(): WipQuery
    {
        return new WipQuery($this->container);
    }

    private function byId(array $rows): array
    {
        return array_column($rows, null, 'id');
    }

    public function testColumnRolesAreMatchedInPhpIgnoringCaseAndPadding(): void
    {
        $this->container['columnModel']->update($this->col($this->pid, 'Done'), ' DONE ');
        $c = $this->q()->columns([$this->pid])[$this->pid];
        $this->assertSame([$this->col($this->pid, 'Ready')], $c['ready']);
        $this->assertSame([$this->col($this->pid, 'In progress')], $c['in_progress']);
        $this->assertSame([$this->col($this->pid, ' DONE ')], $c['done']);
        $this->assertSame('other', WipQuery::role('Backlog'));
    }

    public function testTaskPassCountsSubtasksExcludingCarried(): void
    {
        $t = $this->task($this->pid, 'In progress');
        $this->subtask($t, 'real work', 2);
        $this->subtask($t, 'more work', 1);
        $this->subtask($t, '⏱ carried from subtask 7', 2);
        $row = $this->byId($this->q()->tasks([$this->pid], [], 0))[$t];
        $this->assertSame(2, (int) $row['sub_total']);
        $this->assertSame(1, (int) $row['sub_done']);
        $this->assertSame(1, (int) $row['sub_prog']);
    }

    public function testLastCommentIgnoresReconcilerClamps(): void
    {
        $t = $this->task($this->pid, 'In progress');
        $this->comment($t, 'human note', 1000);
        $this->comment($t, '⏱ reconciler: clamped', 5000);
        $this->assertSame(1000, (int) $this->byId($this->q()->tasks([$this->pid], [], 0))[$t]['last_comment']);
    }

    public function testClosedTasksOnlyOutsideDoneAndInsideTheWindow(): void
    {
        $done = $this->col($this->pid, 'Done');
        $inDone = $this->task($this->pid, 'Done');
        $recent = $this->task($this->pid, 'In progress');
        $old = $this->task($this->pid, 'In progress');
        $open = $this->task($this->pid, 'Done');
        $this->touch($inDone, ['is_active' => 0, 'date_completed' => 5000]);
        $this->touch($recent, ['is_active' => 0, 'date_completed' => 5000]);
        $this->touch($old, ['is_active' => 0, 'date_completed' => 10]);
        $ids = array_map('intval', array_column($this->q()->tasks([$this->pid], [$done], 1000), 'id'));
        sort($ids);
        $this->assertSame([$recent, $open], $ids);
    }

    public function testClosedInDoneIsReturnedOnlyWithALocationInsideTheWindow(): void
    {
        $done = $this->col($this->pid, 'Done');
        $located = $this->task($this->pid, 'Done');
        $emptyLoc = $this->task($this->pid, 'Done');
        $oldLocated = $this->task($this->pid, 'Done');
        $this->container['taskMetadataModel']->save($located, ['loc_session_id' => 'sess-1']);
        $this->container['taskMetadataModel']->save($emptyLoc, ['loc_session_id' => '']);
        $this->container['taskMetadataModel']->save($oldLocated, ['loc_session_id' => 'sess-2']);
        $this->touch($located, ['is_active' => 0, 'date_completed' => 5000]);
        $this->touch($emptyLoc, ['is_active' => 0, 'date_completed' => 5000]);
        $this->touch($oldLocated, ['is_active' => 0, 'date_completed' => 10]);
        $ids = array_map('intval', array_column($this->q()->tasks([$this->pid], [$done], 1000), 'id'));
        $this->assertSame([$located], $ids);
    }

    public function testClosedWindowStillWorksWithNoDoneColumn(): void
    {
        $t = $this->task($this->pid, 'In progress');
        $this->touch($t, ['is_active' => 0, 'date_completed' => 5000]);
        $this->assertCount(1, $this->q()->tasks([$this->pid], [], 1000));
    }

    public function testTaskIdNarrowsEveryPass(): void
    {
        $a = $this->task($this->pid, 'In progress');
        $b = $this->task($this->pid, 'In progress');
        $this->tags($this->pid, $a, ['blocked']);
        $this->tags($this->pid, $b, ['hold']);
        $this->assertSame([$b], array_map('intval', array_column($this->q()->tasks([$this->pid], [], 0, $b), 'id')));
        $this->assertSame([$b => ['hold']], $this->q()->tags([$this->pid], $b));
    }

    public function testTagsAreNormalizedInPhp(): void
    {
        $t = $this->task($this->pid, 'In progress');
        $this->tags($this->pid, $t, ['Blocked', 'wayfinder:Map']);
        $tags = $this->q()->tags([$this->pid])[$t];
        sort($tags);
        $this->assertSame(['blocked', 'wayfinder:map'], $tags);
    }

    public function testMetadataPassReadsOnlyWipKeysWithChangedOn(): void
    {
        $t = $this->task($this->pid, 'In progress');
        $this->container['taskMetadataModel']->save($t, ['moved_by_kind' => 'human', 'unrelated' => 'x']);
        $m = $this->q()->metadata([$this->pid])[$t];
        $this->assertSame(['moved_by_kind'], array_keys($m));
        $this->assertSame('human', $m['moved_by_kind']['value']);
        $this->assertGreaterThan(0, $m['moved_by_kind']['changed_on']);
    }

    public function testEmptyProjectSetIsRefusedNotWidened(): void
    {
        $this->task($this->pid, 'In progress');
        $this->expectException(\LogicException::class);
        $this->q()->tasks([], [], 0);
    }
}
