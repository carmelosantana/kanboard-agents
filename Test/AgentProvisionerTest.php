<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Plugin\Agents\Model\AgentProvisioner;
use Kanboard\Plugin\Agents\Model\AgentTable;
use Kanboard\Model\UserModel;

class AgentProvisionerTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/Sqlite.php';
        \Kanboard\Plugin\Agents\Schema\version_1($this->container['db']->getConnection());
    }

    private function owner(string $username = 'carmelo'): int
    {
        return $this->container['userModel']->create(['username' => $username, 'password' => 'x1234567', 'role' => 'app-user']);
    }

    public function testCreateProvisionsAttributedAgent(): void
    {
        $ownerId = $this->owner();
        $p = new AgentProvisioner($this->container);
        $r = $p->create($ownerId, 'claude');

        $this->assertSame('carmelo.claude', $r['username']);
        $this->assertSame(60, strlen($r['token']));

        $u = $this->container['userModel']->getById($r['agent_user_id']);
        $this->assertSame('carmelo.claude', $u['username']);
        $this->assertSame('app-user', $u['role']);
        $this->assertEquals(1, (int) $u['is_active']);
        $this->assertSame($r['token'], $u['api_access_token']);

        // roster row + group membership recorded
        $row = (new AgentTable($this->container))->getByAgent($r['agent_user_id']);
        $this->assertSame('claude', $row['kind']);
        $this->assertSame($ownerId, (int) $row['owner_user_id']);
    }

    public function testUsernameIncrementsOnCollision(): void
    {
        $ownerId = $this->owner();
        $p = new AgentProvisioner($this->container);
        $this->assertSame('carmelo.claude',   $p->create($ownerId, 'claude')['username']);
        $this->assertSame('carmelo.claude.2', $p->create($ownerId, 'claude')['username']);
        $this->assertSame('carmelo.claude.3', $p->create($ownerId, 'claude')['username']);
    }

    public function testDisableDeactivatesButKeepsRoster(): void
    {
        $ownerId = $this->owner();
        $p = new AgentProvisioner($this->container);
        $r = $p->create($ownerId, 'codex');

        $this->assertTrue($p->disable($r['agent_user_id']));
        $u = $this->container['userModel']->getById($r['agent_user_id']);
        $this->assertEquals(0, (int) $u['is_active']);
        $this->assertNotNull((new AgentTable($this->container))->getByAgent($r['agent_user_id']));
    }

    public function testCanManageOwnershipRules(): void
    {
        $ownerId = $this->owner('alice');
        $otherId = $this->owner('bob');
        $p = new AgentProvisioner($this->container);
        $r = $p->create($ownerId, 'claude');

        $this->assertTrue($p->canManage($r['agent_user_id'], $ownerId, false));   // owner
        $this->assertFalse($p->canManage($r['agent_user_id'], $otherId, false));  // stranger
        $this->assertTrue($p->canManage($r['agent_user_id'], $otherId, true));    // admin
    }

    public function testCreateThrowsAndLeavesNothingWhenTheRosterWriteFails(): void
    {
        $ownerId = $this->owner();
        $pdo = $this->container['db']->getConnection();
        $pdo->exec("CREATE TRIGGER t BEFORE INSERT ON agents BEGIN SELECT RAISE(ABORT, 'injected'); END");
        $thrown = null;
        try {
            (new AgentProvisioner($this->container))->create($ownerId, 'claude');
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        $pdo->exec('DROP TRIGGER t');
        $this->assertNotNull($thrown, 'create() throws when the roster write fails');
        $this->assertEmpty($this->container['userModel']->getByUsername('carmelo.claude'));
        $this->assertSame(0, $this->container['db']->table(UserModel::TABLE)->neq('api_access_token', '')->notNull('api_access_token')->count());
        $this->assertSame([], (new AgentTable($this->container))->getAll());
    }

    public function testCreateDoesNotCommitACallersTransaction(): void
    {
        $ownerId = $this->owner();
        $db = $this->container['db'];
        $db->startTransaction();
        (new AgentProvisioner($this->container))->create($ownerId, 'claude');
        $db->cancelTransaction();
        $this->assertEmpty($this->container['userModel']->getByUsername('carmelo.claude'));
        $this->assertSame([], (new AgentTable($this->container))->getAll());
    }
}
