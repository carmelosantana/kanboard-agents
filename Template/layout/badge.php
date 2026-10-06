<?php if (! empty($wip_flagged)): ?>
<div class="agents-wip-badge-bar">
    <a class="agents-wip-badge" href="<?= $this->url->href('WipController', 'index', ['plugin' => 'Agents']) ?>" title="<?= t('Flagged work in progress') ?>">
        <i class="fa fa-compass" aria-hidden="true"></i> <?= (int) $wip_flagged ?> <?= t('flagged') ?>
    </a>
</div>
<?php endif ?>
