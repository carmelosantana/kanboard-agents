<?php
// One WIP row, as a queue <tr> ($layout 'tr') or an owner-lane card ($layout 'card').
$e = fn ($s) => $this->text->e((string) $s);
$ev = $row['evidence'];
$idle = (int) floor(($env['generated_at'] - $ev['last_activity']) / 86400);
$idleClass = $idle >= 7 ? 'agents-wip-red' : ($idle >= 3 ? 'agents-wip-amber' : '');
$classes = 'agents-wip-row agents-wip-cw'.(int) $row['rank_weight'].($row['flags'] === [] ? ' agents-wip-unflagged' : '');

ob_start();
foreach ($row['flags'] as $k) {
    echo '<span class="agents-wip-pill agents-wip-w'.(int) $catalogue[$k]['weight'].'">'.$e($catalogue[$k]['label']).'</span> ';
}
$pills = ob_get_clean();

$ticket = $this->url->link('#'.$row['task_id'], 'TaskViewController', 'show', ['task_id' => $row['task_id']])
    .' <b>'.$e($row['title']).'</b>'
    .'<div class="agents-wip-mute">'.$e($row['project']).' · '.$e($row['column']).($row['is_active'] ? '' : ' ('.t('closed').')')
    .' · '.(int) $ev['subtasks_done'].'/'.(int) $ev['subtasks_total'].' '.t('subtasks').'</div>';

$who = '<span class="agents-wip-who agents-wip-'.$e($row['owner_kind']).'">'.$e($row['owner_name'] ?? t('nobody')).'</span>'
    .($row['owner_disabled'] ? ' <span class="agents-wip-pill">'.t('disabled').'</span>' : '');
$loc = $row['fix']['location'];
if ($loc !== null) {
    $who .= '<div><span class="agents-wip-loc agents-wip-loc-'.$e($loc['state']).'">'.$e($loc['state']).' · '.$e($loc['host']).'</span>';
    if (! empty($loc['session_id'])) {
        $who .= ' <button type="button" class="agents-wip-copy" data-copy="'.$e($loc['session_id']).'" title="'.t('Copy session id').'">⧉ id</button>'
              .' <button type="button" class="agents-wip-copy" data-copy="'.$e('claude --resume '.$loc['session_id']).'" title="'.t('Copy resume command').'">⧉ resume</button>';
    }
    $who .= '</div>';
}

$action = $row['fix']['action'];
$fixHtml = $this->url->link(t('Open'), 'TaskViewController', 'show', ['task_id' => $row['task_id']], false, 'agents-wip-link');
if ($action !== null) {
    // 'assign' (a disabled owner, Decision 14) is a row marker, not a catalogue flag.
    $def = $action === 'assign' ? ['fix' => t('Assign')] : $catalogue[$action];
    if ($row['fix']['oneclick']) {
        $hidden = $this->form->csrf()
            .'<input type="hidden" name="task_id" value="'.(int) $row['task_id'].'">'
            .'<input type="hidden" name="expected" value="'.(int) $row['date_modification'].'">'
            .'<input type="hidden" name="view" value="'.$e($view).'">';
        $post = $this->url->href('WipController', 'fix', ['plugin' => 'Agents']);
        if ($action === 'noowner') {
            $options = '';
            foreach ($assignees[$row['project_id']] ?? [] as $uid => $username) {
                $options .= '<option value="'.(int) $uid.'">'.$e($username).'</option>';
            }
            $fixHtml = '<form method="post" action="'.$post.'" class="agents-wip-fix">'.$hidden
                .'<input type="hidden" name="fix_action" value="assign">'
                .'<select name="assignee_id" required>'.$options.'</select> '
                .'<button type="submit" class="btn btn-blue">'.$e($def['fix']).'</button></form>';
        } else {
            $fixHtml = '<form method="post" action="'.$post.'" class="agents-wip-fix">'.$hidden
                .'<input type="hidden" name="fix_action" value="move_to_done">'
                .'<button type="submit" class="btn btn-blue">'.$e($def['fix']).'</button></form>';
        }
    } else {
        $fixHtml = $this->url->link($e($def['fix']).' →', 'TaskViewController', 'show', ['task_id' => $row['task_id']], false, 'agents-wip-link');
    }
}
?>
<?php if ($layout === 'tr'): ?>
<tr class="<?= $classes ?>">
    <td><?= $pills ?></td>
    <td><?= $ticket ?></td>
    <td class="agents-wip-owner"><?= $who ?></td>
    <td class="<?= $idleClass ?>"><?= $idle ?>d</td>
    <td><?= $fixHtml ?></td>
</tr>
<?php else: ?>
<div class="<?= $classes ?> agents-wip-card">
    <div><?= $ticket ?></div>
    <div><?= $pills ?> <span class="<?= $idleClass ?>"><?= $idle ?>d</span></div>
    <div class="agents-wip-owner"><?= $who ?></div>
    <div class="agents-wip-cardfix"><?= $fixHtml ?></div>
</div>
<?php endif ?>
