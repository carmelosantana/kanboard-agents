<?php

namespace Kanboard\Plugin\Agents\Model;

// One-off: derive moved_by_* for every task from the latest move/close/open row in
// project_activities. Never overwrites an existing stamp. Returns tasks stamped.
class ProvenanceBackfill
{
    const EVENTS = ['task.move.column', 'task.close', 'task.open'];

    public static function run(\PDO $pdo): int
    {
        $agents = [];
        foreach ($pdo->query('SELECT agent_user_id FROM agents') as $r) {
            $agents[(int) $r['agent_user_id']] = true;
        }
        $stamped = [];
        foreach ($pdo->query("SELECT task_id FROM task_has_metadata WHERE name = 'moved_by_kind'") as $r) {
            $stamped[(int) $r['task_id']] = true;
        }
        $in = "'".implode("','", self::EVENTS)."'";
        $rows = $pdo->query("SELECT task_id, creator_id, date_creation FROM project_activities WHERE event_name IN ($in) ORDER BY task_id, date_creation, id");
        $latest = [];
        foreach ($rows as $r) {
            $latest[(int) $r['task_id']] = $r; // ordered ascending: last write wins
        }
        $ins = $pdo->prepare('INSERT INTO task_has_metadata (task_id, name, value, changed_by, changed_on) VALUES (?, ?, ?, 0, ?)');
        $n = 0;
        foreach ($latest as $tid => $r) {
            if ($tid <= 0 || isset($stamped[$tid])) {
                continue;
            }
            $uid = (int) $r['creator_id'];
            $kind = $uid === 0 ? 'system' : (isset($agents[$uid]) ? 'agent' : 'human');
            $now = time();
            $ins->execute([$tid, 'moved_by_uid', (string) $uid, $now]);
            $ins->execute([$tid, 'moved_by_kind', $kind, $now]);
            $ins->execute([$tid, 'moved_at', (string) (int) $r['date_creation'], $now]);
            $n++;
        }
        return $n;
    }
}
