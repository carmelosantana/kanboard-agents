<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/WipFixture.php';

use KanboardTests\units\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Agents\Model\WipScope;

// The auth matrix: self, agent->owner, app token, admin, PM-on-P, member, empty project set.
class WipScopeTest extends Base
{
    use WipFixture;

    private int $carmelo; private int $claude; private int $admin; private int $pm; private int $member;
    private int $p1; private int $p2; private int $p3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAgents();
        $this->carmelo = $this->user('carmelo');
        $this->claude = $this->agentOf($this->carmelo, 'carmelo.claude');
        $this->admin = $this->user('boss', 'app-admin');
        $this->pm = $this->user('pm');
        $this->member = $this->user('member');
        $this->p1 = $this->project('P1', [$this->carmelo => Role::PROJECT_MEMBER, $this->claude => Role::PROJECT_MEMBER, $this->pm => Role::PROJECT_MANAGER, $this->member => Role::PROJECT_MEMBER]);
        $this->p2 = $this->project('P2', [$this->carmelo => Role::PROJECT_MEMBER, $this->pm => Role::PROJECT_MEMBER]);
        $this->p3 = $this->project('P3', [$this->claude => Role::PROJECT_MEMBER]);
    }

    private function resolve(?int $owner = null, string $scope = 'all', ?array $projects = null): array
    {
        return (new WipScope($this->container))->resolve($owner, $scope, $projects);
    }

    public function testSelfSeesOwnProjectsAndOwnAgents(): void
    {
        $this->actAs($this->carmelo);
        $s = $this->resolve();
        $this->assertSame($this->carmelo, $s['viewer_id']);
        $this->assertNull($s['resolved_from']);
        $this->assertSame([$this->p1, $this->p2], $s['project_ids']);
        $this->assertSame([$this->carmelo, $this->claude], $s['owner_ids']);
        $this->assertSame([$this->claude], array_keys($s['agents']));
        $this->assertEqualsCanonicalizing([$this->p1, $this->p3], $s['agent_projects']);
        $this->assertSame(['user_ids' => [], 'project_ids' => []], $s['denied']);
    }

    public function testScopeChoosesOwners(): void
    {
        $this->actAs($this->carmelo);
        $this->assertSame([$this->carmelo], $this->resolve(null, 'mine')['owner_ids']);
        $this->assertFalse($this->resolve(null, 'mine')['include_unowned']);
        $this->assertSame([$this->claude], $this->resolve(null, 'agents')['owner_ids']);
        $this->assertTrue($this->resolve(null, 'agents')['include_unowned']);
    }

    public function testAgentCallerResolvesToOwnerIntersectedWithItsOwnProjects(): void
    {
        $this->actAs($this->claude);
        $s = $this->resolve();
        $this->assertSame($this->carmelo, $s['viewer_id']);
        $this->assertSame($this->claude, $s['resolved_from']);
        $this->assertTrue($s['caller_is_agent']);
        $this->assertSame([$this->p1], $s['project_ids']); // carmelo {P1,P2} ∩ claude {P1,P3}
    }

    public function testAppTokenRequiresOwner(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->resolve();
    }

    public function testAppTokenSeesAllActiveProjectsForTheNamedOwner(): void
    {
        $s = $this->resolve($this->carmelo);
        $this->assertSame([$this->p1, $this->p2, $this->p3], $s['project_ids']);
        $this->assertSame(0, $s['resolved_from']);
    }

    public function testAdminMayViewAnyUserOnAllProjects(): void
    {
        $this->actAs($this->admin);
        $s = $this->resolve($this->carmelo);
        $this->assertSame([$this->p1, $this->p2, $this->p3], $s['project_ids']);
        $this->assertSame($this->admin, $s['resolved_from']);
    }

    public function testProjectManagerSeesOthersOnlyOnManagedProjects(): void
    {
        $this->actAs($this->pm);
        $s = $this->resolve($this->carmelo, 'all', [$this->p1, $this->p2]);
        $this->assertSame([$this->p1], $s['project_ids']);
        $this->assertSame([$this->p2], $s['denied']['project_ids']);
    }

    public function testPlainMemberCannotViewOthers(): void
    {
        $this->actAs($this->member);
        $s = $this->resolve($this->carmelo);
        $this->assertSame([], $s['project_ids']);
        $this->assertSame([$this->carmelo], $s['denied']['user_ids']);
    }

    public function testUnknownOwnerIsDenied(): void
    {
        $this->actAs($this->admin);
        $this->assertSame([9999], $this->resolve(9999)['denied']['user_ids']);
    }

    public function testRequestedProjectsOutsideReachAreDenied(): void
    {
        $this->actAs($this->carmelo);
        $s = $this->resolve(null, 'all', [$this->p3, 4242]);
        $this->assertSame([], $s['project_ids']);
        $this->assertSame([$this->p3, 4242], $s['denied']['project_ids']);
    }

    public function testUserWithNoProjectsGetsAnEmptySet(): void
    {
        $loner = $this->user('loner');
        $this->actAs($loner);
        $s = $this->resolve();
        $this->assertSame([], $s['project_ids']);
        $this->assertSame(['user_ids' => [], 'project_ids' => []], $s['denied']);
    }
}
