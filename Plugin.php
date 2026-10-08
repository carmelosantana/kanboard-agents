<?php
namespace Kanboard\Plugin\Agents;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Translator;
use Kanboard\Plugin\Agents\Api\AgentsRosterProcedure;
use Kanboard\Plugin\Agents\Api\AgentsWipProcedure;
use Kanboard\Plugin\Agents\Model\WipView;

class Plugin extends Base
{
    public function initialize(): void
    {
        // Entry points: avatar dropdown (My Agents + Work in progress), dashboard sidebar, header badge.
        $this->hook->on('template:header:dropdown', ['template' => 'Agents:header/dropdown']);
        $this->hook->on('template:dashboard:sidebar', ['template' => 'Agents:dashboard/sidebar']);
        $this->hook->on('template:layout:css', ['template' => 'plugins/Agents/Assets/badge.css']);
        $container = $this->container;
        $this->hook->on('template:layout:top', [
            'template' => 'Agents:layout/badge',
            'callable' => function () use ($container) {
                return ['wip_flagged' => (new WipView($container))->flaggedCount()];
            },
        ]);

        // Routes: bare controller name + 'Agents' as the 4th arg.
        $this->route->addRoute('agents',         'AgentController', 'index',   'Agents');
        $this->route->addRoute('agents/create',  'AgentController', 'create',  'Agents');
        $this->route->addRoute('agents/disable', 'AgentController', 'disable', 'Agents');
        $this->route->addRoute('agents/adopt',   'AgentController', 'adopt',   'Agents');
        $this->route->addRoute('wip',            'WipController',   'index',   'Agents');
        $this->route->addRoute('wip/fix',        'WipController',   'fix',     'Agents');

        // JSON-RPC: withObject so core wins any name clash (withCallback/withClassAndMethod would shadow core).
        $this->api->getProcedureHandler()->withObject(new AgentsRosterProcedure($this->container));
        $this->api->getProcedureHandler()->withObject(new AgentsWipProcedure($this->container));

        (new \Kanboard\Plugin\Agents\Subscriber\MoveProvenanceSubscriber($this->container))->register();
    }

    public function getPluginName(): string        { return 'Agents'; }
    public function getPluginDescription(): string { return t('Provision API-only agent users that drive Kanboard via MCP, and see their work in progress.'); }
    public function getPluginAuthor(): string      { return 'Carmelo Santana'; }
    public function getPluginVersion(): string     { return '0.3.3'; }
    public function getCompatibleVersion(): string { return '>=1.2.47'; }
}
