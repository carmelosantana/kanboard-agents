<?php

namespace Kanboard\Plugin\Agents\Model;

// Schema v3 repair: the v2 back-fill ran before the roster was adopted, so agent moves were stamped
// moved_by_kind=human. A uid in the roster can only be an agent, so each task stamped human by a
// roster uid becomes agent. system / uid-0 and non-roster human stamps are never touched.
// The candidates are read first and updated by task_id: MySQL refuses an UPDATE whose subquery
// reads the table being updated. Returns tasks relabelled; a second run returns 0.
// $uid narrows the run to one roster uid (adoptAgent relabels the user it just adopted).
class ProvenanceRelabel
{
    public static function run(\PDO $pdo, ?int $uid = null): int
    {
        $agents = [];
        foreach ($pdo->query('SELECT agent_user_id FROM agents') as $r) {
            if ($uid === null || (int) $r['agent_user_id'] === $uid) {
                $agents[(int) $r['agent_user_id']] = true;
            }
        }
        if ($agents === []) {
            return 0;
        }
        $human = [];
        foreach ($pdo->query("SELECT task_id FROM task_has_metadata WHERE name = 'moved_by_kind' AND value = 'human'") as $r) {
            $human[(int) $r['task_id']] = true;
        }
        $upd = $pdo->prepare("UPDATE task_has_metadata SET value = 'agent' WHERE task_id = ? AND name = 'moved_by_kind' AND value = 'human'");
        $n = 0;
        foreach ($pdo->query("SELECT task_id, value FROM task_has_metadata WHERE name = 'moved_by_uid'") as $r) {
            $tid = (int) $r['task_id'];
            $uid = (int) $r['value'];
            if ($uid <= 0 || ! isset($agents[$uid], $human[$tid])) {
                continue;
            }
            $upd->execute([$tid]);
            $n += $upd->rowCount();
        }
        return $n;
    }
}
