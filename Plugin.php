<?php
namespace Kanboard\Plugin\Agents;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Translator;
use Kanboard\Plugin\Agents\Api\AgentsRosterProcedure;
use Kanboard\Plugin\Agents\Api\AgentsWipProcedure;

class Plugin extends Base
{
    public function initialize(): void
    {
        // Link in the user dropdown (top-right avatar menu).
        $this->hook->on('template:header:dropdown', ['template' => 'Agents:header/dropdown']);

        // Routes: bare controller name + 'Agents' as the 4th arg.
        $this->route->addRoute('agents',         'AgentController', 'index',   'Agents');
        $this->route->addRoute('agents/create',  'AgentController', 'create',  'Agents');
        $this->route->addRoute('agents/disable', 'AgentController', 'disable', 'Agents');
        $this->route->addRoute('agents/adopt',   'AgentController', 'adopt',   'Agents');

        // JSON-RPC: withObject so core wins any name clash (withCallback/withClassAndMethod would shadow core).
        $this->api->getProcedureHandler()->withObject(new AgentsRosterProcedure($this->container));
        $this->api->getProcedureHandler()->withObject(new AgentsWipProcedure($this->container));

        (new \Kanboard\Plugin\Agents\Subscriber\MoveProvenanceSubscriber($this->container))->register();
    }

    public function getPluginName(): string        { return 'Agents'; }
    public function getPluginDescription(): string { return t('Provision API-only agent users that drive Kanboard via MCP.'); }
    public function getPluginAuthor(): string      { return 'Carmelo Santana'; }
    public function getPluginVersion(): string     { return '0.2.0'; }
    public function getCompatibleVersion(): string { return '>=1.2.47'; }
}
