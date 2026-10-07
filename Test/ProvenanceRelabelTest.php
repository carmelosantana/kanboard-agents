<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Agents\Model\ProvenanceRelabel;

// v3 repair: the v2 back-fill ran before the roster was adopted, so agent moves were stamped
// moved_by_kind=human. A roster uid can only be an agent, so human+roster-uid → agent; nothing else moves.
class ProvenanceRelabelTest extends Base
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

    private function stamped(int $uid, string $kind): int
    {
        $t = $this->container['taskCreationModel']->create(['project_id' => $this->pid, 'title' => 'T']);
        $this->container['taskMetadataModel']->save($t, ['moved_by_uid' => (string) $uid, 'moved_by_kind' => $kind, 'moved_at' => '100']);
        return $t;
    }

    private function meta(int $tid): array
    {
        return $this->container['taskMetadataModel']->getAll($tid);
    }

    public function testHumanStampOnRosterUidBecomesAgent(): void
    {
        $t = $this->stamped($this->agent, 'human');
        $this->assertSame(1, ProvenanceRelabel::run($this->pdo));
        $m = $this->meta($t);
        $this->assertSame('agent', $m['moved_by_kind']);
        $this->assertSame((string) $this->agent, $m['moved_by_uid']);
        $this->assertSame('100', $m['moved_at']);
    }

    public function testHumanStampOnNonRosterUidIsUnchanged(): void
    {
        $t = $this->stamped($this->human, 'human');
        $this->assertSame(0, ProvenanceRelabel::run($this->pdo));
        $this->assertSame('human', $this->meta($t)['moved_by_kind']);
    }

    public function testSystemStampIsUnchanged(): void
    {
        $t = $this->stamped(0, 'system');
        $this->assertSame(0, ProvenanceRelabel::run($this->pdo));
        $this->assertSame('system', $this->meta($t)['moved_by_kind']);
    }

    public function testAgentStampIsUnchanged(): void
    {
        $t = $this->stamped($this->agent, 'agent');
        $this->assertSame(0, ProvenanceRelabel::run($this->pdo));
        $this->assertSame('agent', $this->meta($t)['moved_by_kind']);
    }

    public function testOnlyTheRosterRowsMoveInAMixedBoard(): void
    {
        $a = $this->stamped($this->agent, 'human');
        $b = $this->stamped($this->agent, 'human');
        $h = $this->stamped($this->human, 'human');
        $s = $this->stamped(0, 'system');
        $this->assertSame(2, ProvenanceRelabel::run($this->pdo));
        $this->assertSame(['agent', 'agent', 'human', 'system'], array_map(fn ($t) => $this->meta($t)['moved_by_kind'], [$a, $b, $h, $s]));
    }

    public function testIdempotent(): void
    {
        $this->stamped($this->agent, 'human');
        $this->assertSame(1, ProvenanceRelabel::run($this->pdo));
        $this->assertSame(0, ProvenanceRelabel::run($this->pdo));
    }

    public function testSchemaVersion3RunsRelabel(): void
    {
        $t = $this->stamped($this->agent, 'human');
        $this->assertSame(3, \Kanboard\Plugin\Agents\Schema\VERSION);
        \Kanboard\Plugin\Agents\Schema\version_3($this->pdo);
        $this->assertSame('agent', $this->meta($t)['moved_by_kind']);
    }
}
