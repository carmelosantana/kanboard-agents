<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Agents\Model\AgentTable;
use Kanboard\Plugin\Agents\Subscriber\MoveProvenanceSubscriber;

class MoveProvenanceTest extends Base
{
    private $human; private $agent; private $pid; private $tid;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/Sqlite.php';
        \Kanboard\Plugin\Agents\Schema\version_1($this->container['db']->getConnection());
        $u = $this->container['userModel'];
        $this->human = $u->create(['username' => 'carmelo', 'password' => 'x123456', 'role' => 'app-admin']);
        $this->agent = $u->create(['username' => 'carmelo.claude', 'password' => 'x123456', 'role' => 'app-user']);
        (new AgentTable($this->container))->insert($this->human, $this->agent, 'claude');
        $this->pid = $this->container['projectModel']->create(['name' => 'P']);
        $this->tid = $this->container['taskCreationModel']->create(['project_id' => $this->pid, 'title' => 'T']);
        (new MoveProvenanceSubscriber($this->container))->register();
    }

    private function actAs($uid): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($uid));
    }

    private function meta(): array
    {
        return $this->container['taskMetadataModel']->getAll($this->tid);
    }

    public function testIsAgent(): void
    {
        $t = new AgentTable($this->container);
        $this->assertTrue($t->isAgent($this->agent));
        $this->assertFalse($t->isAgent($this->human));
    }

    public function testCloseByAgentStampsAgent(): void
    {
        $this->actAs($this->agent);
        $this->assertTrue($this->container['taskStatusModel']->close($this->tid));
        $m = $this->meta();
        $this->assertSame((string) $this->agent, $m['moved_by_uid']);
        $this->assertSame('agent', $m['moved_by_kind']);
        $this->assertGreaterThan(time() - 60, (int) $m['moved_at']);
    }

    public function testMoveByHumanStampsHuman(): void
    {
        $this->actAs($this->human);
        $task = $this->container['taskFinderModel']->getById($this->tid);
        $this->container['taskPositionModel']->movePosition($this->pid, $this->tid, 2, 1, $task['swimlane_id']);
        $this->assertSame('human', $this->meta()['moved_by_kind']);
    }

    public function testNotLoggedInStampsSystem(): void
    {
        // app-token API calls: no user session
        $this->container['taskStatusModel']->close($this->tid);
        $m = $this->meta();
        $this->assertSame('system', $m['moved_by_kind']);
        $this->assertSame('0', $m['moved_by_uid']);
    }

    public function testReopenRestampsLatestActor(): void
    {
        $this->actAs($this->agent);
        $this->container['taskStatusModel']->close($this->tid);
        $this->actAs($this->human);
        $this->container['taskStatusModel']->open($this->tid);
        $this->assertSame('human', $this->meta()['moved_by_kind']);
    }
}
