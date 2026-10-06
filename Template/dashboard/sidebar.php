<li <?= $this->app->checkMenuSelection('WipController', 'index', 'Agents') ?>>
    <?= $this->url->link(t('Work in progress'), 'WipController', 'index', ['plugin' => 'Agents']) ?>
</li>
