<div class="panel">
    <form method="post" action="<?= $this->url->href('AgentProjectController', 'addAll', ['plugin' => 'Agents', 'project_id' => $project['id']]) ?>" class="form-inline">
        <?= $this->form->csrf() ?>
        <?= $this->form->label(t('Agents'), 'agents_role') ?>
        <select name="role" id="form-agents_role" aria-label="<?= t('Role for all agents') ?>">
            <option value="project-member" selected><?= t('Member') ?></option>
            <option value="project-manager"><?= t('Manager') ?></option>
        </select>
        <button type="submit" class="btn btn-blue"><?= t('Add all agents') ?></button>
    </form>
</div>
