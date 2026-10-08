<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Agents\Api\AgentsRosterProcedure;
use Kanboard\Plugin\Agents\Model\AgentTable;

class AgentProcedureTest extends Base
{
    private int $admin; private int $owner; private int $manager;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/Sqlite.php';
        \Kanboard\Plugin\Agents\Schema\version_1($this->container['db']->getConnection());
        $u = $this->container['userModel'];
        $this->admin = $u->create(['username' => 'claude.admin', 'password' => 'x1234567', 'role' => 'app-admin']);
        $this->owner = $u->create(['username' => 'carmelo', 'password' => 'x1234567', 'role' => 'app-user']);
        $this->manager = $u->create(['username' => 'carmelo.claude', 'password' => 'x1234567', 'role' => 'app-manager']);
    }

    private function actAs(int $uid): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($uid));
    }

    private function rpc(): AgentsRosterProcedure
    {
        return new AgentsRosterProcedure($this->container);
    }

    public function testAdminCreatesAnAgentAndGetsTheTokenOnce(): void
    {
        $this->actAs($this->admin);
        $r = $this->rpc()->createAgent($this->owner, 'codex', '');
        $this->assertTrue($r['ok']);
        $this->assertSame('carmelo.codex', $r['username']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{20,}$/', $r['token']);
        $this->assertTrue((new AgentTable($this->container))->isAgent($r['agent_user_id']));
        $this->assertSame('app-user', $this->container['userModel']->getById($r['agent_user_id'])['role']);
    }

    public function testNonAdminsAndAnonymousAreForbidden(): void
    {
        $this->assertSame(['ok' => false, 'reason' => 'forbidden'], $this->rpc()->createAgent($this->owner, 'codex', ''));
        foreach ([$this->manager, $this->owner] as $uid) {
            $this->actAs($uid);
            $this->assertSame(['ok' => false, 'reason' => 'forbidden'], $this->rpc()->createAgent($this->owner, 'codex', ''));
            $this->assertSame(['ok' => false, 'reason' => 'forbidden'], $this->rpc()->getAgents(0));
            $this->assertSame(['ok' => false, 'reason' => 'forbidden'], $this->rpc()->disableAgent($this->manager));
        }
        $this->assertSame([], (new AgentTable($this->container))->getAll());
    }

    public function testCreateValidatesItsInput(): void
    {
        $this->actAs($this->admin);
        $this->assertSame('invalid_kind', $this->rpc()->createAgent($this->owner, 'Bad Kind!', '')['reason']);
        $this->assertSame('unknown_user', $this->rpc()->createAgent(99999, 'codex', '')['reason']);
        $a = $this->rpc()->createAgent($this->owner, 'codex', '');
        $this->assertSame('owner_is_agent', $this->rpc()->createAgent($a['agent_user_id'], 'codex', '')['reason']);
    }

    public function testGetAgentsListsTheRosterWithoutSecrets(): void
    {
        $this->actAs($this->admin);
        $a = $this->rpc()->createAgent($this->owner, 'codex', 'Codex bot');
        $r = $this->rpc()->getAgents(0);
        $this->assertTrue($r['ok']);
        $this->assertCount(1, $r['agents']);
        $row = $r['agents'][0];
        $this->assertSame(['agent_user_id', 'username', 'name', 'owner_user_id', 'kind', 'is_active'], array_keys($row));
        $this->assertSame($a['agent_user_id'], $row['agent_user_id']);
        $this->assertSame('Codex bot', $row['name']);
        $this->assertSame(1, $row['is_active']);
        $this->assertStringNotContainsString($a['token'], json_encode($r));
        $this->assertSame([], $this->rpc()->getAgents($this->manager)['agents']);
    }

    public function testDisableOnlyTouchesRosterAgents(): void
    {
        $this->actAs($this->admin);
        $a = $this->rpc()->createAgent($this->owner, 'codex', '');
        $this->assertSame(['ok' => true, 'agent_user_id' => $a['agent_user_id']], $this->rpc()->disableAgent($a['agent_user_id']));
        $this->assertSame(0, (int) $this->container['userModel']->getById($a['agent_user_id'])['is_active']);
        $this->assertSame(['ok' => false, 'reason' => 'not_agent'], $this->rpc()->disableAgent($this->owner));
        $this->assertSame(['ok' => false, 'reason' => 'not_agent'], $this->rpc()->disableAgent($this->admin));
        $this->assertSame(1, (int) $this->container['userModel']->getById($this->owner)['is_active']);
    }

    public function testCreateFailureIsARefusalNotAnException(): void
    {
        // Exhaust nextUsername (base + .2..999) so create() throws inside createForApi.
        $db = $this->container['db'];
        $db->startTransaction();
        $db->table('users')->insert(['username' => 'carmelo.codex', 'password' => 'x']);
        for ($n = 2; $n < 1000; $n++) {
            $db->table('users')->insert(['username' => 'carmelo.codex.'.$n, 'password' => 'x']);
        }
        $db->closeTransaction();
        $this->actAs($this->admin);
        $this->assertSame(['ok' => false, 'reason' => 'create_failed'], $this->rpc()->createAgent($this->owner, 'codex', ''));
        $this->assertSame([], (new AgentTable($this->container))->getAll());
    }

    public function testGetAgentsFiltersByOwner(): void
    {
        $this->actAs($this->admin);
        $other = $this->container['userModel']->create(['username' => 'someone', 'password' => 'x1234567', 'role' => 'app-user']);
        $mine = $this->rpc()->createAgent($this->owner, 'codex', '');
        $this->rpc()->createAgent($other, 'codex', '');
        $r = $this->rpc()->getAgents($this->owner);
        $this->assertTrue($r['ok']);
        $this->assertSame([$mine['agent_user_id']], array_column($r['agents'], 'agent_user_id'));
        $this->assertSame($this->owner, $r['agents'][0]['owner_user_id']);
        $this->assertCount(2, $this->rpc()->getAgents(0)['agents']);
    }
}
