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

    public function createAgent($owner_user_id, $kind, $label = '')
    {
        return (new AgentProvisioner($this->container))->createForApi($owner_user_id, $kind, $label);
    }

    public function getAgents($owner_user_id = 0)
    {
        return (new AgentProvisioner($this->container))->listForApi($owner_user_id);
    }

    public function disableAgent($agent_user_id)
    {
        return (new AgentProvisioner($this->container))->disableForApi($agent_user_id);
    }
}
