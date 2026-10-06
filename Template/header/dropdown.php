<li>
    <?= $this->url->link('<i class="fa fa-android fa-fw"></i>'.t('My Agents'), 'AgentController', 'index', ['plugin' => 'Agents']) ?>
</li>
<li>
    <?= $this->url->link('<i class="fa fa-compass fa-fw"></i>'.t('Work in progress'), 'WipController', 'index', ['plugin' => 'Agents']) ?>
</li>
