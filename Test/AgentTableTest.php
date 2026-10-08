<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;

class AgentTableTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        // Namespaced free functions are NOT PSR-4 autoloadable — require the schema file first, then create the table.
        require_once __DIR__.'/../Schema/Sqlite.php';
        \Kanboard\Plugin\Agents\Schema\version_1($this->container['db']->getConnection());
    }

    public function testSchemaCreatesAgentsTable(): void
    {
        $this->assertSame(0, $this->container['db']->table('agents')->count());
    }

    public function testInsertAndQuery(): void
    {
        $t = new \Kanboard\Plugin\Agents\Model\AgentTable($this->container);
        $this->assertNotFalse($t->insert(1, 2, 'claude'));
        $this->assertNotFalse($t->insert(1, 3, 'codex'));
        $this->assertNotFalse($t->insert(9, 4, 'ollama'));

        $this->assertCount(2, $t->getByOwner(1));
        $this->assertCount(3, $t->getAll());
        $row = $t->getByAgent(3);
        $this->assertSame('codex', $row['kind']);
        $this->assertSame(1, (int) $row['owner_user_id']);
        $this->assertNull($t->getByAgent(999));
    }

    public function testAgentIdsReturnsRosterSubset(): void
    {
        $t = new \Kanboard\Plugin\Agents\Model\AgentTable($this->container);
        $t->insert(1, 2, 'claude');
        $t->insert(1, 3, 'codex');

        $ids = $t->agentIds([1, 2, 3, 4, 2, '3']);
        sort($ids);
        $this->assertSame([2, 3], $ids);
    }

    public function testAgentIdsEmptyInputSkipsQuery(): void
    {
        $t = new \Kanboard\Plugin\Agents\Model\AgentTable($this->container);
        $this->assertSame([], $t->agentIds([]));
    }
}
