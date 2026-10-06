<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/WipFixture.php';

use KanboardTests\units\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Agents\Model\WipCatalogue;
use Kanboard\Plugin\Agents\Model\WipFixService;
use Kanboard\Plugin\Agents\Model\WipView;

// Renders the page templates with a real envelope: both layouts, the one-click forms, no inline JS.
class WipPageTest extends Base
{
    use WipFixture;

    private int $carmelo; private int $claude; private int $pid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAgents();
        $this->carmelo = $this->user('carmelo');
        $this->claude = $this->agentOf($this->carmelo, 'carmelo.claude');
        $this->pid = $this->project('P', [$this->carmelo => Role::PROJECT_MEMBER, $this->claude => Role::PROJECT_MEMBER]);
        $this->actAs($this->carmelo);
    }

    private function render(string $view): string
    {
        [$env, $scope] = (new WipView($this->container))->build(null, 'all', null, true);
        $fix = new WipFixService($this->container);
        return $this->container['template']->render('Agents:wip/index', [
            'env' => $env, 'scope' => $scope, 'view' => $view,
            'catalogue' => (new WipCatalogue())->flags(),
            'assignees' => [$this->pid => $fix->assignees($this->pid, $scope)],
        ]);
    }

    private function board(): array
    {
        $closed = $this->task($this->pid, 'In progress', $this->claude, 'closed early');
        $this->touch($closed, ['is_active' => 0, 'date_completed' => time() - 3600]);
        $unowned = $this->task($this->pid, 'In progress', 0, 'nobody owns me');
        $plain = $this->task($this->pid, 'Backlog', $this->carmelo, 'quiet one');
        return [$closed, $unowned, $plain];
    }

    public function testQueueRendersRowsFormsAndNoInlineScript(): void
    {
        [$closed, $unowned, $plain] = $this->board();
        $html = $this->render('queue');
        $this->assertStringContainsString('agents-wip-queue', $html);
        $this->assertStringContainsString('closed early', $html);
        $this->assertStringContainsString('name="fix_action" value="move_to_done"', $html);
        $this->assertStringContainsString('name="fix_action" value="assign"', $html);
        $this->assertStringContainsString('<option value="'.$this->claude.'">carmelo.claude</option>', $html);
        $this->assertStringContainsString('name="csrf_token"', $html);
        $this->assertMatchesRegularExpression('/<tr class="[^"]*agents-wip-unflagged/', $html);
        $this->assertStringContainsString('plugins/Agents/Assets/wip.js', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/', $html, 'inline <script> breaks under CSP');
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+="/', $html, 'inline event handlers break under CSP');
        $this->assertStringContainsString('<b>—</b> Merged PR, still open', $html);
    }

    public function testOwnersViewGroupsByOwner(): void
    {
        $this->board();
        $html = $this->render('owners');
        $this->assertStringContainsString('agents-wip-lanes', $html);
        $this->assertStringContainsString('<b>carmelo.claude</b>', $html);
        $this->assertStringContainsString('<b>Unowned</b>', $html);
        $this->assertStringContainsString('<b>carmelo</b>', $html);
    }

    public function testNoOwnerWithNobodyAssignableRendersALinkNotADeadForm(): void
    {
        $q = $this->project('Q', [$this->carmelo => Role::PROJECT_VIEWER, $this->claude => Role::PROJECT_VIEWER]);
        $this->task($q, 'In progress', 0, 'nobody can take me');
        $html = $this->render('queue');
        $this->assertStringContainsString('nobody can take me', $html);
        $this->assertStringNotContainsString('name="assignee_id"', $html);
        $this->assertStringContainsString('Assign →', $html);
    }

    public function testEmptyViewSaysSo(): void
    {
        $this->assertStringContainsString('Nothing in progress', $this->render('queue'));
    }

    public function testBadgeCountsFlaggedAndRendersNothingAtZero(): void
    {
        $view = new WipView($this->container);
        $this->assertSame(0, $view->flaggedCount());
        $this->assertSame('', trim($this->container['template']->render('Agents:layout/badge', ['wip_flagged' => 0])));
        $this->board();
        $this->assertSame(2, $view->flaggedCount());
        $this->assertStringContainsString('2 flagged', $this->container['template']->render('Agents:layout/badge', ['wip_flagged' => 2]));
        $this->actAsAppToken();
        $this->assertSame(0, $view->flaggedCount());
    }
}
