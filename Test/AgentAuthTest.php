<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Plugin\Agents\Model\AgentProvisioner;

class AgentAuthTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/Sqlite.php';
        \Kanboard\Plugin\Agents\Schema\version_1($this->container['db']->getConnection());
    }

    public function testOnlyOwnerOrAdminCanManage(): void
    {
        $owner = $this->container['userModel']->create(['username' => 'owner1', 'password' => 'x1234567', 'role' => 'app-user']);
        $other = $this->container['userModel']->create(['username' => 'other1', 'password' => 'x1234567', 'role' => 'app-user']);
        $p = new AgentProvisioner($this->container);
        $agent = $p->create($owner, 'claude')['agent_user_id'];

        $this->assertTrue($p->canManage($agent, $owner, false));
        $this->assertFalse($p->canManage($agent, $other, false));
    }
}
