<?php
namespace Kanboard\Plugin\Agents;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Translator;

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
    }

    public function getPluginName(): string        { return 'Agents'; }
    public function getPluginDescription(): string { return t('Provision API-only agent users that drive Kanboard via MCP.'); }
    public function getPluginAuthor(): string      { return 'Carmelo Santana'; }
    public function getPluginVersion(): string     { return '0.1.0'; }
    public function getCompatibleVersion(): string { return '>=1.2.47'; }
}
