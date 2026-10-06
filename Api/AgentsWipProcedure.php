<?php
namespace Kanboard\Plugin\Agents\Api;

use Kanboard\Api\Procedure\BaseProcedure;
use Kanboard\Plugin\Agents\Model\WipFixService;
use Kanboard\Plugin\Agents\Model\WipView;

// JSON-RPC: the WIP view. Prefixed short name because core's ACL maps key on short class names.
// Holds ONLY RPC methods (every method on a withObject instance is remotely callable, private ones
// included) and declares params without type hints (a TypeError is a fatal with no JSON-RPC body).
class AgentsWipProcedure extends BaseProcedure
{
    public function getWipFlags($owner_user_id = null, $scope = 'all', $project_ids = null, $include_unflagged = false, $closed_lookback_days = null)
    {
        return (new WipView($this->container))->flags($owner_user_id, $scope, $project_ids, $include_unflagged, $closed_lookback_days);
    }

    public function applyWipFix($task_id, $action, $expected_date_modification, $assignee_id = null)
    {
        return (new WipFixService($this->container))->apply($task_id, $action, $expected_date_modification, $assignee_id);
    }
}
