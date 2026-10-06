<table class="table-striped agents-wip-queue">
    <tr>
        <th><?= t('Flags') ?></th>
        <th><?= t('Ticket') ?></th>
        <th class="agents-wip-owner"><?= t('Owner · Location') ?></th>
        <th><?= t('Idle') ?></th>
        <th><?= t('Fix') ?></th>
    </tr>
    <?php foreach ($env['rows'] as $row): ?>
        <?= $this->render('Agents:wip/row', [
            'row' => $row, 'layout' => 'tr', 'env' => $env, 'view' => $view, 'catalogue' => $catalogue, 'assignees' => $assignees,
        ]) ?>
    <?php endforeach ?>
</table>
