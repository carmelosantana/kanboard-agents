<?php
$e = fn ($s) => $this->text->e((string) $s);
$flagged = $env['summary']['flagged'];
?>
<?= $this->asset->css('plugins/Agents/Assets/wip.css') ?>
<?= $this->asset->js('plugins/Agents/Assets/wip.js') ?>
<div class="agents-wip" data-view="<?= $e($view) ?>">
    <div class="page-header">
        <h2><?= t('Work in progress') ?></h2>
        <ul>
            <li <?= $view === 'queue' ? 'class="active"' : '' ?>><?= $this->url->link(t('Queue'), 'WipController', 'index', ['plugin' => 'Agents']) ?></li>
            <li <?= $view === 'owners' ? 'class="active"' : '' ?>><?= $this->url->link(t('By owner'), 'WipController', 'index', ['plugin' => 'Agents', 'view' => 'owners']) ?></li>
            <li><?= $this->url->link(t('Refresh'), 'WipController', 'index', ['plugin' => 'Agents', 'view' => $view]) ?></li>
            <li><label class="agents-wip-toggle"><input type="checkbox" id="agents-wip-unflagged"> <?= t('show unflagged') ?></label></li>
        </ul>
    </div>

    <div class="agents-wip-summary">
        <span class="agents-wip-big"><b><?= (int) $env['summary']['in_progress'] ?></b> <?= t('in progress') ?></span>
        <span class="agents-wip-big"><b><?= (int) $flagged ?></b> <?= t('flagged') ?></span>
        <?php foreach ($catalogue as $key => $def): $n = $env['summary'][$key]; ?>
            <span class="agents-wip-sum <?= $n ? 'agents-wip-w'.(int) $def['weight'] : 'agents-wip-zero' ?>"
                  title="<?= $n === null ? $e(t('Needs %s, which does not exist yet', $def['requires'])) : '' ?>">
                <b><?= $n === null ? '—' : (int) $n ?></b> <?= $e($def['label']) ?>
            </span>
        <?php endforeach ?>
    </div>

    <?php if (! empty($env['unmapped_projects'])): ?>
        <p class="agents-wip-mute">
            <?= t('%d project(s) lack an In progress, Ready or Done column, so some flags cannot apply there.', count($env['unmapped_projects'])) ?>
        </p>
    <?php endif ?>

    <?php if (empty($env['rows'])): ?>
        <p class="alert"><?= t('Nothing in progress for you or your agents.') ?></p>
    <?php else: ?>
        <?= $this->render('Agents:wip/'.$view, [
            'env' => $env, 'scope' => $scope, 'view' => $view, 'catalogue' => $catalogue, 'assignees' => $assignees,
        ]) ?>
    <?php endif ?>
    <p class="agents-wip-mute"><?= t('Generated %s', $this->dt->datetime($env['generated_at'])) ?></p>
</div>
