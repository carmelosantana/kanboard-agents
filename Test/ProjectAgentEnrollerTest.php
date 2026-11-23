<?php
require_once 'tests/units/Base.php';
use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Agents\Controller\AgentProjectController;
use Kanboard\Plugin\Agents\Model\AgentTable;
use Kanboard\Plugin\Agents\Model\ProjectAgentEnroller;
use PHPUnit\Framework\Attributes\DataProvider;

class ProjectAgentEnrollerTest extends Base
{
    private int $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/Sqlite.php';
        \Kanboard\Plugin\Agents\Schema\version_1($this->container['db']->getConnection());
        $this->projectId = (int) $this->container['projectModel']->create(['name' => 'Board']);
        $_SESSION['user'] = $this->container['userModel']->getById(1);
        $this->container['response'] = $this->createMock(Response::class);
    }

    private function user(string $name, int $active = 1): int
    {
        $id = (int) $this->container['userModel']->create(['username' => $name, 'password' => 'x1234567', 'role' => Role::APP_USER]);
        if ($active === 0) {
            $this->container['userModel']->disable($id);
        }
        return $id;
    }

    private function agent(string $name, int $active = 1): int
    {
        $id = $this->user($name, $active);
        (new AgentTable($this->container))->insert(1, $id, 'claude');
        return $id;
    }

    private function role(int $userId): string
    {
        return $this->container['projectUserRoleModel']->getUserRole($this->projectId, $userId);
    }

    private function post(array $post): void
    {
        $this->container['request'] = new Request(
            $this->container,
            ['REQUEST_METHOD' => 'POST', 'QUERY_STRING' => 'project_id='.$this->projectId],
            ['project_id' => $this->projectId],
            $post,
            [],
            []
        );
    }

    public function testAddsEveryActiveAgentWithTheChosenRole(): void
    {
        $a = $this->agent('a.claude');
        $b = $this->agent('b.codex');
        $human = $this->user('human');

        $r = (new ProjectAgentEnroller($this->container))->addAll($this->projectId, Role::PROJECT_MANAGER);

        $this->assertSame(['added' => 2, 'existing' => 0], $r);
        $this->assertSame(Role::PROJECT_MANAGER, $this->role($a));
        $this->assertSame(Role::PROJECT_MANAGER, $this->role($b));
        $this->assertSame('', $this->role($human));
    }

    public function testSkipsAgentsAlreadyOnTheProjectWithoutChangingTheirRole(): void
    {
        $a = $this->agent('a.claude');
        $b = $this->agent('b.codex');
        $this->container['projectUserRoleModel']->addUser($this->projectId, $a, Role::PROJECT_VIEWER);

        $r = (new ProjectAgentEnroller($this->container))->addAll($this->projectId, Role::PROJECT_MEMBER);

        $this->assertSame(['added' => 1, 'existing' => 1], $r);
        $this->assertSame(Role::PROJECT_VIEWER, $this->role($a));
        $this->assertSame(Role::PROJECT_MEMBER, $this->role($b));
    }

    public function testSkipsAgentsWhoReachTheProjectThroughAGroup(): void
    {
        $a = $this->agent('a.claude');
        $groupId = (int) $this->container['groupModel']->create('Bots');
        $this->container['groupMemberModel']->addUser($groupId, $a);
        $this->container['projectGroupRoleModel']->addGroup($this->projectId, $groupId, Role::PROJECT_VIEWER);

        $r = (new ProjectAgentEnroller($this->container))->addAll($this->projectId, Role::PROJECT_MEMBER);

        $this->assertSame(['added' => 0, 'existing' => 1], $r);
        $this->assertSame(Role::PROJECT_VIEWER, $this->role($a));
    }

    public function testSkipsDisabledAgents(): void
    {
        $off = $this->agent('off.claude', 0);
        $on = $this->agent('on.claude');

        $r = (new ProjectAgentEnroller($this->container))->addAll($this->projectId, Role::PROJECT_MEMBER);

        $this->assertSame(['added' => 1, 'existing' => 0], $r);
        $this->assertSame('', $this->role($off));
        $this->assertSame(Role::PROJECT_MEMBER, $this->role($on));
    }

    /** @dataProvider rejectedRoles */
    #[DataProvider('rejectedRoles')]
    public function testRejectsAnyRoleButMemberOrManager(string $role): void
    {
        $a = $this->agent('a.claude');

        $this->assertNull((new ProjectAgentEnroller($this->container))->addAll($this->projectId, $role));
        $this->assertSame('', $this->role($a));
    }

    public static function rejectedRoles(): array
    {
        return [[Role::PROJECT_VIEWER], [Role::APP_ADMIN], ['custom'], ['']];
    }

    public function testControllerAddsAllAndRedirectsToPermissions(): void
    {
        $a = $this->agent('a.claude');
        $this->post(['csrf_token' => $this->container['token']->getCSRFToken(), 'role' => Role::PROJECT_MEMBER]);
        $this->container['response']->expects($this->once())->method('redirect')
            ->with($this->stringContains('ProjectPermissionController'));

        (new AgentProjectController($this->container))->addAll();

        $this->assertSame(Role::PROJECT_MEMBER, $this->role($a));
        $this->assertSame('Added 1 agents as Member (0 already on the project).', session_get('flash')['success'] ?? null);
    }

    public function testControllerRejectsAnInvalidRole(): void
    {
        $a = $this->agent('a.claude');
        $this->post(['csrf_token' => $this->container['token']->getCSRFToken(), 'role' => Role::PROJECT_VIEWER]);

        (new AgentProjectController($this->container))->addAll();

        $this->assertSame('', $this->role($a));
        $this->assertNotEmpty(session_get('flash')['failure'] ?? null);
    }

    public function testControllerRequiresCsrf(): void
    {
        $this->agent('a.claude');
        $this->post(['role' => Role::PROJECT_MEMBER]);

        $this->expectException(AccessForbiddenException::class);
        (new AgentProjectController($this->container))->addAll();
    }

    public function testControllerForbidsAProjectMember(): void
    {
        $a = $this->agent('a.claude');
        $member = $this->user('member');
        $this->container['projectUserRoleModel']->addUser($this->projectId, $member, Role::PROJECT_MEMBER);
        $_SESSION['user'] = $this->container['userModel']->getById($member);
        $this->post(['csrf_token' => $this->container['token']->getCSRFToken(), 'role' => Role::PROJECT_MANAGER]);

        try {
            (new AgentProjectController($this->container))->addAll();
            $this->fail('expected AccessForbiddenException');
        } catch (AccessForbiddenException $e) {
            $this->assertSame('', $this->role($a));
        }
    }

    public function testControllerAllowsAProjectManager(): void
    {
        $a = $this->agent('a.claude');
        $manager = $this->user('boss');
        $this->container['projectUserRoleModel']->addUser($this->projectId, $manager, Role::PROJECT_MANAGER);
        $_SESSION['user'] = $this->container['userModel']->getById($manager);
        $this->post(['csrf_token' => $this->container['token']->getCSRFToken(), 'role' => Role::PROJECT_MEMBER]);

        (new AgentProjectController($this->container))->addAll();

        $this->assertSame(Role::PROJECT_MEMBER, $this->role($a));
    }

    public function testControllerForbidsAPrivateProject(): void
    {
        $a = $this->agent('a.claude');
        $private = (int) $this->container['projectModel']->create(['name' => 'Mine', 'is_private' => 1], 1, true);
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'POST'], ['project_id' => $private],
            ['csrf_token' => $this->container['token']->getCSRFToken(), 'role' => Role::PROJECT_MEMBER], [], []);

        $this->expectException(AccessForbiddenException::class);
        (new AgentProjectController($this->container))->addAll();
    }

    public function testPermissionsPageHookRendersTheForm(): void
    {
        $html = $this->container['template']->render('Agents:project_permission/add_all', [
            'project' => ['id' => $this->projectId],
        ]);

        $this->assertStringContainsString('AgentProjectController', $html);
        $this->assertStringContainsString('value="project-member" selected', $html);
        $this->assertStringContainsString('value="project-manager"', $html);
        $this->assertStringNotContainsString('project-viewer', $html);
        $this->assertStringContainsString('Add all agents', $html);
        $this->assertStringContainsString('csrf_token', $html);
    }
}
