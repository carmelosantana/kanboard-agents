<?php
namespace Kanboard\Plugin\Agents\Model;

use Kanboard\Core\Base;

// The flat passes behind getWipFlags (research #4881): columns, task pass, metadata pass, tag pass.
// The roster pass lives in WipScope. Grouped derived tables only, never correlated subqueries
// (2.0 s on Postgres, 8.3 s on SQLite at 10x). Column titles and tag names are matched in PHP,
// because MariaDB's utf8mb4_unicode_ci compares case- and pad-space-insensitively.
class WipQuery extends Base
{
    const META_KEYS = [
        'loc_state', 'loc_last_seen', 'loc_host', 'loc_session_id', 'loc_socket', 'loc_transcript',
        'pr_state', 'time_backfilled_at', 'blocked_on', 'moved_by_uid', 'moved_by_kind', 'moved_at',
    ];
    const CARRIED_PREFIX = '⏱ carried';
    const RECONCILER_PREFIX = '⏱ reconciler';

    public static function role(string $title): string
    {
        return match (mb_strtolower(trim($title))) {
            'in progress' => 'in_progress',
            'ready' => 'ready',
            'done' => 'done',
            default => 'other',
        };
    }

    public static function normalizeTag(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /** @return array<int, array{in_progress:int[], ready:int[], done:int[], titles:array<int,string>}> keyed by project id */
    public function columns(array $projectIds): array
    {
        $this->guard($projectIds);
        $out = [];
        foreach ($projectIds as $pid) {
            $out[(int) $pid] = ['in_progress' => [], 'ready' => [], 'done' => [], 'titles' => []];
        }
        $rows = $this->db->execute(
            'SELECT id, project_id, title FROM columns WHERE project_id IN ('.$this->marks($projectIds).') ORDER BY position',
            array_map('intval', $projectIds)
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $c) {
            $pid = (int) $c['project_id'];
            $id = (int) $c['id'];
            $out[$pid]['titles'][$id] = $c['title'];
            $role = self::role($c['title']);
            if ($role !== 'other') {
                $out[$pid][$role][] = $id;
            }
        }
        return $out;
    }

    /**
     * Open tasks, plus tasks closed since $closedSince that sit outside Done (mismatch) or sit in Done
     * with a non-empty `loc_session_id` (timemiss). One row per task. The EXISTS probe is the one
     * correlated subquery: it runs only for closed rows inside the lookback, on the metadata primary key.
     */
    public function tasks(array $projectIds, array $doneColumnIds, int $closedSince, ?int $taskId = null): array
    {
        $this->guard($projectIds);
        $closed = 't.date_completed >= ?';
        $closedParams = [$closedSince];
        if ($doneColumnIds !== []) {
            $closed .= ' AND (t.column_id NOT IN ('.$this->marks($doneColumnIds).')
                         OR EXISTS (SELECT 1 FROM task_has_metadata lm
                                     WHERE lm.task_id = t.id AND lm.name = ? AND lm.value <> ?))';
            $closedParams = array_merge($closedParams, array_map('intval', $doneColumnIds), ['loc_session_id', '']);
        }
        $sql = 'SELECT t.id, t.title, t.project_id, prj.name AS project_name, t.column_id, t.owner_id, t.is_active,
                       t.date_moved, t.date_modification, t.date_completed,
                       COALESCE(st.n, 0) AS sub_total, COALESCE(st.d, 0) AS sub_done, COALESCE(st.ip, 0) AS sub_prog,
                       cm.last_comment
                  FROM tasks t
                  JOIN projects prj ON prj.id = t.project_id
                  LEFT JOIN (SELECT task_id, COUNT(*) AS n,
                                    SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) AS d,
                                    SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS ip
                               FROM subtasks WHERE title NOT LIKE ? GROUP BY task_id) st ON st.task_id = t.id
                  LEFT JOIN (SELECT task_id, MAX(date_creation) AS last_comment
                               FROM comments WHERE comment NOT LIKE ? GROUP BY task_id) cm ON cm.task_id = t.id
                 WHERE t.project_id IN ('.$this->marks($projectIds).')
                   AND (t.is_active = ? OR ('.$closed.'))';
        // is_active is BOOLEAN on Postgres: compare through a bound param (PicoDb binds PARAM_STR), as core does.
        $params = array_merge(
            [self::CARRIED_PREFIX.'%', self::RECONCILER_PREFIX.'%'],
            array_map('intval', $projectIds),
            [1],
            $closedParams
        );
        if ($taskId !== null) {
            $sql .= ' AND t.id = ?';
            $params[] = $taskId;
        }
        return $this->db->execute($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string, array{value:string, changed_on:int}>> keyed by task id */
    public function metadata(array $projectIds, ?int $taskId = null): array
    {
        $this->guard($projectIds);
        $sql = 'SELECT m.task_id, m.name, m.value, m.changed_on
                  FROM task_has_metadata m JOIN tasks t ON t.id = m.task_id
                 WHERE t.project_id IN ('.$this->marks($projectIds).')
                   AND m.name IN ('.$this->marks(self::META_KEYS).')';
        $params = array_merge(array_map('intval', $projectIds), self::META_KEYS);
        if ($taskId !== null) {
            $sql .= ' AND t.id = ?';
            $params[] = $taskId;
        }
        $out = [];
        foreach ($this->db->execute($sql, $params)->fetchAll(\PDO::FETCH_ASSOC) as $m) {
            $out[(int) $m['task_id']][$m['name']] = ['value' => (string) $m['value'], 'changed_on' => (int) $m['changed_on']];
        }
        return $out;
    }

    /** @return array<int, string[]> normalized tag names keyed by task id */
    public function tags(array $projectIds, ?int $taskId = null): array
    {
        $this->guard($projectIds);
        $sql = 'SELECT x.task_id, g.name
                  FROM task_has_tags x JOIN tags g ON g.id = x.tag_id JOIN tasks t ON t.id = x.task_id
                 WHERE t.project_id IN ('.$this->marks($projectIds).')';
        $params = array_map('intval', $projectIds);
        if ($taskId !== null) {
            $sql .= ' AND t.id = ?';
            $params[] = $taskId;
        }
        $out = [];
        foreach ($this->db->execute($sql, $params)->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['task_id']][] = self::normalizeTag($r['name']);
        }
        return $out;
    }

    // PicoDb's in('x', []) silently drops the condition and returns every row (research #4881).
    // Callers return early on an empty project set; this is the backstop.
    private function guard(array $projectIds): void
    {
        if ($projectIds === []) {
            throw new \LogicException('WipQuery needs at least one project id');
        }
    }

    private function marks(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}
