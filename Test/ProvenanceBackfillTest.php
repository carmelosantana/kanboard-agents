<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Agents\Model\ProvenanceBackfill;

class ProvenanceBackfillTest extends Base
{
    private $pdo; private $human; private $agent; private $pid;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/Sqlite.php';
        $this->pdo = $this->container['db']->getConnection();
        \Kanboard\Plugin\Agents\Schema\version_1($this->pdo);
        $u = $this->container['userModel'];
        $this->human = $u->create(['username' => 'carmelo', 'password' => 'x123456']);
        $this->agent = $u->create(['username' => 'carmelo.claude', 'password' => 'x123456']);
        $this->pdo->exec("INSERT INTO agents (owner_user_id, agent_user_id, kind, created_at) VALUES ($this->human, $this->agent, 'claude', 0)");
        $this->pid = $this->container['projectModel']->create(['name' => 'P']);
    }

    private function task(): int
    {
        return $this->container['taskCreationModel']->create(['project_id' => $this->pid, 'title' => 'T']);
    }

    private function activity(int $tid, int $uid, string $event, int $ts): void
    {
        $st = $this->pdo->prepare('INSERT INTO project_activities (date_creation, event_name, creator_id, project_id, task_id, data) VALUES (?,?,?,?,?,?)');
        $st->execute([$ts, $event, $uid, $this->pid, $tid, '{}']);
    }

    private function meta(int $tid): array
    {
        return $this->container['taskMetadataModel']->getAll($tid);
    }

    public function testLatestMoveEventWins(): void
    {
        $t = $this->task();
        $this->activity($t, $this->human, 'task.move.column', 100);
        $this->activity($t, $this->agent, 'task.close', 200);
        $this->activity($t, $this->human, 'comment.create', 300); // not a move: ignored
        $this->assertSame(1, ProvenanceBackfill::run($this->pdo));
        $m = $this->meta($t);
        $this->assertSame('agent', $m['moved_by_kind']);
        $this->assertSame((string) $this->agent, $m['moved_by_uid']);
        $this->assertSame('200', $m['moved_at']);
    }

    public function testExistingStampIsNotOverwritten(): void
    {
        $t = $this->task();
        $this->container['taskMetadataModel']->save($t, ['moved_by_kind' => 'human', 'moved_by_uid' => '9', 'moved_at' => '999']);
        $this->activity($t, $this->agent, 'task.close', 200);
        $this->assertSame(0, ProvenanceBackfill::run($this->pdo));
        $this->assertSame('human', $this->meta($t)['moved_by_kind']);
    }

    public function testTaskWithoutHistoryGetsNothing(): void
    {
        $t = $this->task();
        $this->assertSame(0, ProvenanceBackfill::run($this->pdo));
        $this->assertArrayNotHasKey('moved_by_kind', $this->meta($t));
    }

    public function testIdempotent(): void
    {
        $t = $this->task();
        $this->activity($t, $this->human, 'task.open', 50);
        ProvenanceBackfill::run($this->pdo);
        $this->assertSame(0, ProvenanceBackfill::run($this->pdo));
    }

    public function testSchemaVersion2RunsBackfill(): void
    {
        $t = $this->task();
        $this->activity($t, $this->agent, 'task.move.column', 10);
        \Kanboard\Plugin\Agents\Schema\version_2($this->pdo);
        $this->assertSame('agent', $this->meta($t)['moved_by_kind']);
    }
}
