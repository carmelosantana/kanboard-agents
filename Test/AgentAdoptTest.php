<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Agents\Api\AgentsRosterProcedure;
use Kanboard\Plugin\Agents\Model\AgentTable;

class AgentAdoptTest extends Base
{
    private int $admin; private int $owner; private int $bot;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/Sqlite.php';
        \Kanboard\Plugin\Agents\Schema\version_1($this->container['db']->getConnection());
        $u = $this->container['userModel'];
        $this->admin = $u->create(['username' => 'boss', 'password' => 'x1234567', 'role' => 'app-admin']);
        $this->owner = $u->create(['username' => 'carmelo', 'password' => 'x1234567', 'role' => 'app-user']);
        $this->bot = $u->create(['username' => 'carmelo.claude', 'password' => 'x1234567', 'role' => 'app-manager']);
    }

    private function actAs(int $uid): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($uid));
    }

    private function rpc(): AgentsRosterProcedure
    {
        return new AgentsRosterProcedure($this->container);
    }

    public function testTableAdoptRefusesDuplicates(): void
    {
        $t = new AgentTable($this->container);
        $this->assertTrue($t->adopt($this->owner, $this->bot, 'claude'));
        $this->assertFalse($t->adopt($this->admin, $this->bot, 'codex'));
        $this->assertCount(1, $t->getAll());
    }

    public function testAdminAdoptsAnExistingUser(): void
    {
        $this->actAs($this->admin);
        $r = $this->rpc()->adoptAgent($this->bot, $this->owner, 'claude');
        $this->assertSame(['ok' => true, 'agent_user_id' => $this->bot, 'owner_user_id' => $this->owner, 'kind' => 'claude'], $r);
        $this->assertTrue((new AgentTable($this->container))->isAgent($this->bot));
    }

    public function testNumericStringParamsAreAccepted(): void
    {
        $this->actAs($this->admin);
        $this->assertTrue($this->rpc()->adoptAgent((string) $this->bot, (string) $this->owner, 'Claude')['ok']);
    }

    public function testSecondAdoptIsDuplicate(): void
    {
        $this->actAs($this->admin);
        $this->rpc()->adoptAgent($this->bot, $this->owner, 'claude');
        $this->assertSame(['ok' => false, 'reason' => 'duplicate'], $this->rpc()->adoptAgent($this->bot, $this->owner, 'claude'));
    }

    public function testNonAdminIsForbidden(): void
    {
        $this->actAs($this->owner);
        $this->assertSame(['ok' => false, 'reason' => 'forbidden'], $this->rpc()->adoptAgent($this->bot, $this->owner, 'claude'));
    }

    public function testAppTokenIsForbidden(): void
    {
        // No session initialised = the jsonrpc app token (getId() 0, isAdmin() false).
        $this->assertSame(['ok' => false, 'reason' => 'forbidden'], $this->rpc()->adoptAgent($this->bot, $this->owner, 'claude'));
    }

    public function testValidation(): void
    {
        $this->actAs($this->admin);
        $this->assertSame('unknown_user', $this->rpc()->adoptAgent(9999, $this->owner, 'claude')['reason']);
        $this->assertSame('invalid_kind', $this->rpc()->adoptAgent($this->bot, $this->owner, 'no spaces')['reason']);
        $this->assertSame('invalid_kind', $this->rpc()->adoptAgent($this->bot, $this->owner, ['claude'])['reason']);
        $this->assertSame('unknown_user', $this->rpc()->adoptAgent([$this->bot], $this->owner, 'claude')['reason']);
        $this->assertSame('self', $this->rpc()->adoptAgent($this->owner, $this->owner, 'claude')['reason']);
    }
}
