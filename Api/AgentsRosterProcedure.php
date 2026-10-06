<?php
namespace Kanboard\Plugin\Agents\Api;

use Kanboard\Api\Procedure\BaseProcedure;
use Kanboard\Plugin\Agents\Model\AgentProvisioner;

// JSON-RPC: roster administration. Holds ONLY RPC methods: every method on a withObject
// instance is remotely callable, private ones included (research #4880). Params carry no
// type hints: a TypeError is a fatal with no JSON-RPC body.
class AgentsRosterProcedure extends BaseProcedure
{
    public function adoptAgent($agent_user_id, $owner_user_id, $kind)
    {
        return (new AgentProvisioner($this->container))->adopt($agent_user_id, $owner_user_id, $kind);
    }
}
