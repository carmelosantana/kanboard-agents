<?php
namespace Kanboard\Plugin\Agents\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\Agents\Model\ProjectAgentEnroller;

class AgentProjectController extends BaseController
{
    /** POST from Project → Permissions: add every active agent with the chosen role. */
    public function addAll()
    {
        $this->checkCSRFForm();
        $project = $this->getProject();

        // Same rule as core's permissions page: project manager (or admin), shared projects only.
        if ((int) $project['is_private'] === 1
            || ! $this->helper->user->hasProjectAccess('ProjectPermissionController', 'addUser', $project['id'])) {
            throw new AccessForbiddenException();
        }

        $values = $this->request->getValues(); // read once: getValues() strips csrf_token as it reads
        $role = (string) ($values['role'] ?? '');
        $r = (new ProjectAgentEnroller($this->container))->addAll((int) $project['id'], $role);

        if ($r === null) {
            $this->flash->failure(t('Pick Member or Manager.'));
        } else {
            $this->flash->success(t(
                'Added %d agents as %s (%d already on the project).',
                $r['added'],
                ProjectAgentEnroller::roleLabel($role),
                $r['existing']
            ));
        }

        $this->response->redirect($this->helper->url->to('ProjectPermissionController', 'index', ['project_id' => $project['id']]));
    }
}
