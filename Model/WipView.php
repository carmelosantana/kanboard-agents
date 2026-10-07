<?php
namespace Kanboard\Plugin\Agents\Model;

use Kanboard\Core\Base;

// Builds the getWipFlags envelope: WipScope -> WipQuery passes -> facts -> WipFlagRules -> ranked rows.
// One source of truth for the RPC, the page and the header badge. Read-only.
class WipView extends Base
{
    const ROLE_LABELS = ['in_progress' => 'In progress', 'ready' => 'Ready', 'done' => 'Done'];
    const MAX_LOOKBACK_DAYS = 365;

    /** The getWipFlags envelope. Params are untyped RPC input; malformed ones throw InvalidArgumentException. */
    public function flags($ownerUserId = null, $scope = 'all', $projectIds = null, $includeUnflagged = false, $closedLookbackDays = null, ?int $now = null): array
    {
        return $this->build($ownerUserId, $scope, $projectIds, $includeUnflagged, $closedLookbackDays, $now)[0];
    }

    /** The header badge: flagged rows in the caller's own view. 0 when logged out or on any failure,
     *  so a WIP bug never takes down every page. */
    public function flaggedCount(): int
    {
        if (! $this->userSession->isLogged()) {
            return 0;
        }
        try {
            return (int) $this->flags()['summary']['flagged'];
        } catch (\Throwable $e) {
            $this->logger->error('Agents WIP badge: '.$e->getMessage());
            return 0;
        }
    }

    /** @return array{0: array, 1: array} the envelope and the resolved WipScope */
    public function build($ownerUserId = null, $scope = 'all', $projectIds = null, $includeUnflagged = false, $closedLookbackDays = null, ?int $now = null): array
    {
        $cat = new WipCatalogue();
        $now = $now ?? time();

        $owner = null;
        if ($ownerUserId !== null) {
            $owner = Params::id($ownerUserId);
            if ($owner === 0) {
                throw new \InvalidArgumentException('owner_user_id must be a positive id');
            }
        }
        if (! is_string($scope) || ! in_array($scope, WipScope::SCOPES, true)) {
            throw new \InvalidArgumentException('scope must be one of '.implode('|', WipScope::SCOPES));
        }
        $projects = Params::idList($projectIds, 'project_ids');
        $unflagged = Params::flag($includeUnflagged, 'include_unflagged');
        $lookback = $closedLookbackDays === null ? $cat->threshold('closed_lookback_days') : Params::id($closedLookbackDays);
        if ($lookback < 1 || $lookback > self::MAX_LOOKBACK_DAYS) {
            throw new \InvalidArgumentException('closed_lookback_days must be 1..'.self::MAX_LOOKBACK_DAYS);
        }

        $s = (new WipScope($this->container))->resolve($owner, $scope, $projects);
        // WipScope does not narrow agent_projects for a manager viewing another user: keep it within project_ids.
        $s['agent_projects'] = array_values(array_intersect($s['agent_projects'], $s['project_ids']));

        $summary = ['in_progress' => 0, 'flagged' => 0];
        foreach ($cat->flags() as $key => $_) {
            $summary[$key] = in_array($key, $cat->unavailable(), true) ? null : 0;
        }
        $env = [
            'catalogue_version' => $cat->version(),
            'generated_at' => $now,
            'viewer' => ['user_id' => $s['viewer_id'], 'resolved_from' => $s['resolved_from']],
            'scope' => $scope,
            'summary' => $summary,
            'unavailable_flags' => $cat->unavailable(),
            'unmapped_projects' => [],
            'rows' => [],
            'denied' => $s['denied'],
        ];

        // PicoDb in('project_id', []) drops the condition and returns every task: never query an empty set.
        if ($s['project_ids'] === []) {
            return [$env, $s];
        }

        $columns = (new WipQuery($this->container))->columns($s['project_ids']);
        foreach ($columns as $pid => $c) {
            $missing = [];
            foreach (self::ROLE_LABELS as $role => $label) {
                if ($c[$role] === []) {
                    $missing[] = $label;
                }
            }
            if ($missing !== []) {
                $env['unmapped_projects'][] = ['project_id' => $pid, 'missing' => $missing];
            }
        }

        $rows = [];
        $fixer = new WipFixService($this->container);
        $assignable = [];
        foreach ($this->facts($s, $columns, $now - $lookback * 86400) as $f) {
            $ev = WipFlagRules::evaluate($f, $cat, $now, $lookback, $cat->available());
            if ($f['is_active'] === 1 && $f['role'] === 'in_progress') {
                $env['summary']['in_progress']++;
            }
            if ($ev['flags'] !== []) {
                $env['summary']['flagged']++;
            }
            foreach ($ev['flags'] as $k) {
                $env['summary'][$k]++;
            }
            // include_unflagged admits only open In-progress tickets; flagged rows are always in.
            if ($ev['flags'] === [] && ! ($unflagged && $f['is_active'] === 1 && $f['role'] === 'in_progress')) {
                continue;
            }
            $hasAssignee = false;
            if ($ev['fix'] === 'noowner') {
                $assignable[$f['project_id']] ??= $fixer->callerAssignees($f['project_id']) !== [];
                $hasAssignee = $assignable[$f['project_id']];
            }
            $rows[] = $this->row($f, $ev, $s, $fixer->oneClickable($ev['fix'], $f, $hasAssignee, $cat, $columns));
        }

        $env['rows'] = array_map(function ($r) {
            unset($r['sort_ts']);
            return $r;
        }, WipFlagRules::rank($rows));

        return [$env, $s];
    }

    /**
     * Facts for every in-scope candidate task (see WipFlagRules for the shape), plus display fields
     * (title, project_name, column_title, project_id). $taskId narrows the passes to one task.
     */
    public function facts(array $scope, array $columns, int $closedSince, ?int $taskId = null): array
    {
        $q = new WipQuery($this->container);
        $pids = $scope['project_ids'];
        $done = [];
        foreach ($columns as $c) {
            $done = array_merge($done, $c['done']);
        }
        $meta = $q->metadata($pids, $taskId);
        $tags = $q->tags($pids, $taskId);

        $out = [];
        foreach ($q->tasks($pids, $done, $closedSince, $taskId) as $t) {
            $f = self::fact($t, $columns, $scope, $tags[(int) $t['id']] ?? [], $meta[(int) $t['id']] ?? []);
            if ($this->inScope($f, $scope)) {
                $out[] = $f;
            }
        }
        return $out;
    }

    /**
     * One raw WipQuery::tasks() row as typed facts. Drivers return ids and SUM/COUNT/MAX as strings
     * (MariaDB always), and WipFlagRules compares strictly, so every numeric field is cast here.
     */
    public static function fact(array $t, array $columns, array $scope, array $tags, array $meta): array
    {
        $pid = (int) $t['project_id'];
        $title = $columns[$pid]['titles'][(int) $t['column_id']] ?? '';
        $owner = (int) $t['owner_id'];
        return [
            'task_id' => (int) $t['id'],
            'title' => $t['title'],
            'project_id' => $pid,
            'project_name' => $t['project_name'],
            'column_id' => (int) $t['column_id'],
            'column_title' => $title,
            'is_active' => (int) $t['is_active'],
            'role' => WipQuery::role($title),
            'has_done' => $columns[$pid]['done'] !== [],
            'owner_id' => $owner,
            'owner_kind' => $owner === 0 ? 'none' : (in_array($owner, $scope['roster_agent_ids'], true) ? 'agent' : 'human'),
            'agent_member' => in_array($pid, $scope['agent_projects'], true),
            'date_moved' => (int) $t['date_moved'],
            'date_modification' => (int) $t['date_modification'],
            'date_completed' => (int) $t['date_completed'],
            'last_comment' => $t['last_comment'] === null ? null : (int) $t['last_comment'],
            'sub_total' => (int) $t['sub_total'],
            'sub_done' => (int) $t['sub_done'],
            'sub_prog' => (int) $t['sub_prog'],
            'tags' => $tags,
            'meta' => $meta,
        ];
    }

    public static function location(array $meta): ?array
    {
        if (! isset($meta['loc_state'])) {
            return null;
        }
        return [
            'state' => $meta['loc_state']['value'],
            'host' => $meta['loc_host']['value'] ?? null,
            'session_id' => $meta['loc_session_id']['value'] ?? null,
            'socket' => $meta['loc_socket']['value'] ?? null,
            'transcript' => $meta['loc_transcript']['value'] ?? null,
        ];
    }

    // Decision 7: the viewer's own tickets plus their Roster agents'. Decision 9: unowned tickets
    // only when In progress on a project where one of the viewer's agents is a member.
    private function inScope(array $f, array $scope): bool
    {
        if ($f['owner_id'] === 0) {
            return $scope['include_unowned'] && $f['is_active'] === 1 && $f['role'] === 'in_progress' && $f['agent_member'];
        }
        return in_array($f['owner_id'], $scope['owner_ids'], true);
    }

    private function row(array $f, array $ev, array $scope, bool $oneClickable): array
    {
        $loc = self::location($f['meta']);
        $person = $scope['people'][$f['owner_id']] ?? null;
        $disabled = $person !== null && $person['is_active'] === 0;
        return [
            'task_id' => $f['task_id'],
            'title' => $f['title'],
            'project_id' => $f['project_id'],
            'project' => $f['project_name'],
            'column' => $f['column_title'],
            'is_active' => $f['is_active'],
            'date_modification' => $f['date_modification'],
            'owner_id' => $f['owner_id'],
            'owner_name' => $person['username'] ?? null,
            'owner_kind' => $f['owner_kind'],
            'owner_disabled' => $disabled,
            'flags' => $ev['flags'],
            'rank_weight' => $ev['rank_weight'],
            'fix' => [
                // Decision 14: a disabled owner's ticket needs a new owner first: Assign, as a link.
                'action' => $disabled ? 'assign' : $ev['fix'],
                'oneclick' => ! $disabled && $oneClickable,
                'url' => '/task/'.$f['task_id'],
                'location' => $loc,
            ],
            'evidence' => [
                'date_moved' => $f['date_moved'],
                'last_activity' => WipFlagRules::lastActivity($f),
                'subtasks_done' => $f['sub_done'],
                'subtasks_total' => $f['sub_total'],
                'blocked_since' => in_array('blocked', $ev['flags'], true) ? WipFlagRules::blockedSince($f) : null,
                'moved_by_kind' => $f['meta']['moved_by_kind']['value'] ?? null,
                'location' => $loc,
            ],
            'sort_ts' => $ev['sort_ts'],
        ];
    }
}
