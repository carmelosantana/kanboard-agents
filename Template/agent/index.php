<div class="page-header"><h2><?= t('My Agents') ?></h2></div>

<form method="post" action="<?= $this->url->href('AgentController', 'create', ['plugin' => 'Agents']) ?>" class="form-inline">
    <?= $this->form->csrf() ?>
    <?= $this->form->label(t('Kind'), 'kind') ?>
    <?= $this->form->select('kind', ['claude' => 'claude', 'codex' => 'codex', 'ollama' => 'ollama'], [], []) ?>
    <?= $this->form->label(t('Label (optional)'), 'label') ?>
    <?= $this->form->text('label', [], [], ['placeholder' => t('Display name')]) ?>
    <button type="submit" class="btn btn-blue"><?= t('Create agent') ?></button>
</form>

<?php if ($is_admin): ?>
<h3><?= t('Adopt an existing user') ?></h3>
<form method="post" action="<?= $this->url->href('AgentController', 'adopt', ['plugin' => 'Agents']) ?>" class="form-inline">
    <?= $this->form->csrf() ?>
    <?= $this->form->label(t('User'), 'agent_user_id') ?>
    <?= $this->form->select('agent_user_id', $users, [], []) ?>
    <?= $this->form->label(t('Owner'), 'owner_user_id') ?>
    <?= $this->form->select('owner_user_id', $users, [], []) ?>
    <label for="form-adopt-kind"><?= t('Kind') ?></label>
    <input type="text" name="kind" id="form-adopt-kind" placeholder="claude" required pattern="[a-z0-9\-]{1,32}">
    <button type="submit" class="btn btn-blue"><?= t('Adopt') ?></button>
</form>
<?php endif ?>

<table class="table-striped">
    <tr><th><?= t('Agent') ?></th><th><?= t('Kind') ?></th><?= $is_admin ? '<th>'.t('Owner').'</th>' : '' ?><th><?= t('Status') ?></th><th><?= t('Actions') ?></th></tr>
    <?php foreach ($agents as $a): ?>
    <tr>
        <td><?= $this->text->e($a['username']) ?></td>
        <td><?= $this->text->e($a['kind']) ?></td>
        <?php if ($is_admin): ?><td><?= (int) $a['owner_user_id'] ?></td><?php endif ?>
        <td><?= $a['is_active'] ? t('Active') : t('Disabled') ?></td>
        <td>
            <?php if ($a['is_active']): ?>
                <?= $this->url->link(t('Disable'), 'AgentController', 'disable', ['plugin' => 'Agents', 'agent_user_id' => $a['agent_user_id'], 'csrf_token' => $this->app->getToken()->getCSRFToken()], true) ?>
            <?php endif ?>
        </td>
    </tr>
    <?php endforeach ?>
</table>
