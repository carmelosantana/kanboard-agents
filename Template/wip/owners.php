<?php
// One lane per owner: the viewer, then each Roster agent, then Unowned. Same rows, regrouped.
$lanes = [];
foreach (array_keys($scope['people']) as $uid) {
    $lanes[$uid] = [];
}
$lanes[0] = [];
foreach ($env['rows'] as $row) {
    $lanes[$row['owner_id']][] = $row;
}
?>
<div class="agents-wip-lanes">
    <?php foreach ($lanes as $uid => $rows): if ($rows === []) continue; $flagged = count(array_filter($rows, fn ($r) => $r['flags'] !== [])); ?>
        <div class="agents-wip-lane">
            <div class="agents-wip-lanehead">
                <b><?= $uid === 0 ? t('Unowned') : $this->text->e($scope['people'][$uid]['username']) ?></b>
                <span class="agents-wip-mute"><?= t('%d tickets · %d flagged', count($rows), $flagged) ?></span>
            </div>
            <?php foreach ($rows as $row): ?>
                <?= $this->render('Agents:wip/row', [
                    'row' => $row, 'layout' => 'card', 'env' => $env, 'view' => $view, 'catalogue' => $catalogue, 'assignees' => $assignees,
                ]) ?>
            <?php endforeach ?>
        </div>
    <?php endforeach ?>
</div>
