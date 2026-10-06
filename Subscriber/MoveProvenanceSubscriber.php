<?php

namespace Kanboard\Plugin\Agents\Subscriber;

use Kanboard\Core\Base;
use Kanboard\Model\TaskModel;
use Kanboard\Plugin\Agents\Model\AgentTable;

// Stamps who moved a task (agent | human | system) into task metadata on every
// column move, close and reopen. Read by kanboard-mcp's Position rules ("a human's move wins").
class MoveProvenanceSubscriber extends Base
{
    const EVENTS = [TaskModel::EVENT_MOVE_COLUMN, TaskModel::EVENT_CLOSE, TaskModel::EVENT_OPEN];

    public function register(): void
    {
        foreach (self::EVENTS as $name) {
            // addListener (not Plugin\Base::on) so the event object reaches us.
            $this->dispatcher->addListener($name, [$this, 'stamp']);
        }
    }

    public function stamp($event): void
    {
        $taskId = (int) $event->getTaskId();
        if ($taskId <= 0) {
            return;
        }
        $uid = $this->userSession->isLogged() ? (int) $this->userSession->getId() : 0;
        if ($uid === 0) {
            $kind = 'system';
        } else {
            $kind = (new AgentTable($this->container))->isAgent($uid) ? 'agent' : 'human';
        }
        $this->taskMetadataModel->save($taskId, [
            'moved_by_uid' => (string) $uid,
            'moved_by_kind' => $kind,
            'moved_at' => (string) time(),
        ]);
    }
}
