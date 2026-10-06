<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Plugin\Agents\Controller\AgentController;
use Kanboard\Plugin\Agents\Model\AgentTable;

class AgentControllerTest extends Base
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/Sqlite.php';
        \Kanboard\Plugin\Agents\Schema\version_1($this->container['db']->getConnection());
        $_SESSION['user'] = $this->container['userModel']->getById(1);
        $this->container['response'] = $this->createMock(Response::class);
    }

    private function post(array $post): void
    {
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'POST'], [], $post, [], []);
    }

    public function testCreateAcceptsTheFormBodyCsrfToken(): void
    {
        // The My Agents form sends csrf_token in the POST body, not the query string.
        $this->post(['csrf_token' => $this->container['token']->getCSRFToken(), 'kind' => 'codex', 'label' => '']);

        (new AgentController($this->container))->create();

        $this->assertCount(1, (new AgentTable($this->container))->getByOwner(1));
    }

    public function testAdoptFormPatternsCompileUnderTheVFlag(): void
    {
        // Browsers compile pattern="" with the RegExp v flag, where an unescaped '-' in a class is a
        // SyntaxError: the pattern is dropped and the console logs an error on every submit.
        $html = $this->container['template']->render('Agents:agent/index', [
            'agents' => [], 'is_admin' => true, 'users' => [1 => 'admin'],
        ]);

        $this->assertStringContainsString('pattern="[a-z0-9\-]{1,32}"', $html);
        $this->assertSame(0, preg_match('/pattern="[^"]*[^\\\\]-\]/', $html));
    }

    public function testCreateRejectsAMissingCsrfToken(): void
    {
        $this->post(['kind' => 'codex']);

        $this->expectException(AccessForbiddenException::class);
        (new AgentController($this->container))->create();
    }
}
