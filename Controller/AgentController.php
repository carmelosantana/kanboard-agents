<?php
namespace Kanboard\Plugin\Agents\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Plugin\Agents\Model\AgentProvisioner;
use Kanboard\Plugin\Agents\Model\AgentTable;

class AgentController extends BaseController
{
    public function index()
    {
        $table = new AgentTable($this->container);
        $isAdmin = $this->userSession->isAdmin();
        $rows = $isAdmin ? $table->getAll() : $table->getByOwner($this->userSession->getId());

        // Decorate with the agent user's active state for display.
        foreach ($rows as &$r) {
            $u = $this->userModel->getById((int) $r['agent_user_id']);
            $r['username']  = $u['username'] ?? '(deleted)';
            $r['is_active'] = isset($u['is_active']) ? (int) $u['is_active'] : 0;
        }
        unset($r);

        $this->response->html($this->helper->layout->app('Agents:agent/index', [
            'title'    => t('My Agents'),
            'agents'   => $rows,
            'is_admin' => $isAdmin,
            'users'    => $isAdmin ? $this->userModel->getActiveUsersList() : [],
        ]));
    }

    public function create()
    {
        $this->checkCSRFForm();
        $values = $this->request->getValues();
        $kind = isset($values['kind']) ? preg_replace('/[^a-z0-9-]/', '', strtolower($values['kind'])) : '';
        if ($kind === '') {
            $this->flash->failure(t('Pick an agent kind.'));
            $this->response->redirect($this->helper->url->to('AgentController', 'index', ['plugin' => 'Agents']));
            return;
        }
        $label = isset($values['label']) ? trim($values['label']) : '';
        $result = (new AgentProvisioner($this->container))->create($this->userSession->getId(), $kind, $label);

        $base = rtrim($this->helper->url->base(), '/');
        $this->response->html($this->helper->layout->app('Agents:agent/created', [
            'title'    => t('Agent created'),
            'result'   => $result,
            'endpoint' => $base.'/jsonrpc.php',
        ]));
    }

    public function disable()
    {
        $this->checkCSRFParam();
        $agentId = $this->request->getIntegerParam('agent_user_id');
        $p = new AgentProvisioner($this->container);
        if (! $p->canManage($agentId, $this->userSession->getId(), $this->userSession->isAdmin())) {
            $this->flash->failure(t('Not allowed.'));
        } else {
            $p->disable($agentId);
            $this->flash->success(t('Agent disabled.'));
        }
        $this->response->redirect($this->helper->url->to('AgentController', 'index', ['plugin' => 'Agents']));
    }

    /** Admin-only: register an existing user as an agent of an owner (Kanboard #4882). */
    public function adopt()
    {
        $this->checkCSRFForm();
        $values = $this->request->getValues();
        $r = (new AgentProvisioner($this->container))->adopt(
            $values['agent_user_id'] ?? null,
            $values['owner_user_id'] ?? null,
            $values['kind'] ?? ''
        );
        if ($r['ok']) {
            $this->flash->success(t('Agent adopted.'));
        } else {
            $this->flash->failure(t('Adopt refused: %s.', $r['reason']));
        }
        $this->response->redirect($this->helper->url->to('AgentController', 'index', ['plugin' => 'Agents']));
    }
}
