<?php
namespace Kanboard\Plugin\Agents\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Plugin\Agents\Model\WipCatalogue;
use Kanboard\Plugin\Agents\Model\WipFixService;
use Kanboard\Plugin\Agents\Model\WipView;

// The in-Kanboard WIP page (route `wip`): the ranked queue by default, `?view=owners` for owner lanes.
class WipController extends BaseController
{
    const REASONS = [
        'changed' => 'the ticket changed since this page loaded; refresh and try again',
        'not_flagged' => 'the flag no longer holds',
        'forbidden' => 'you may not fix this ticket',
        'hold' => 'tickets tagged hold or wayfinder:* are never moved to Done',
        'invalid_assignee' => 'pick yourself or one of your agents who is a member of the project',
        'no_done_column' => 'this project has no Done column',
        'invalid' => 'the request was malformed',
    ];

    public function index()
    {
        $view = $this->request->getStringParam('view') === 'owners' ? 'owners' : 'queue';
        [$env, $scope] = (new WipView($this->container))->build(null, 'all', null, true);

        $fix = new WipFixService($this->container);
        $assignees = [];
        foreach ($env['rows'] as $r) {
            if ($r['fix']['action'] === 'noowner' && ! isset($assignees[$r['project_id']])) {
                $assignees[$r['project_id']] = $fix->callerAssignees($r['project_id']);
            }
        }

        $this->response->html($this->helper->layout->app('Agents:wip/index', [
            'title' => t('Work in progress'),
            'env' => $env,
            'scope' => $scope,
            'view' => $view,
            'catalogue' => (new WipCatalogue())->flags(),
            'assignees' => $assignees,
        ]));
    }

    public function fix()
    {
        $this->checkCSRFForm();
        $v = $this->request->getValues();
        $view = ($v['view'] ?? '') === 'owners' ? 'owners' : 'queue';
        try {
            $r = (new WipFixService($this->container))->apply(
                $v['task_id'] ?? null,
                $v['fix_action'] ?? null,
                $v['expected'] ?? null,
                ($v['assignee_id'] ?? '') === '' ? null : $v['assignee_id']
            );
        } catch (\InvalidArgumentException $e) {
            $r = ['ok' => false, 'reason' => 'invalid'];
        }

        if ($r['ok']) {
            $this->flash->success(t('Fixed #%d.', $r['task_id']));
        } else {
            $this->flash->failure(t('Not fixed: %s.', t(self::REASONS[$r['reason']] ?? $r['reason'])));
        }
        $this->response->redirect($this->helper->url->to('WipController', 'index', ['plugin' => 'Agents', 'view' => $view]));
    }
}
