<?php
namespace Kanboard\Plugin\Agents\Model;

// Pure flag rules: row facts + catalogue + now -> flags, rank, fix. No DB, no session.
//
// Facts shape (built by WipView::facts):
//   task_id int, is_active 0|1, role 'in_progress'|'ready'|'done'|'other', has_done bool,
//   owner_id int, owner_kind 'agent'|'human'|'none', agent_member bool,
//   date_moved int, date_modification int, date_completed int, last_comment ?int,
//   sub_total int, sub_done int, sub_prog int        (subtasks titled "⏱ carried…" already excluded),
//   tags string[]                                    (lower-cased, trimmed),
//   meta array<name, array{value: string, changed_on: int}>
final class WipFlagRules
{
    const BLOCK_TAGS = ['blocked', 'needs-info'];

    public static function lastActivity(array $f): int
    {
        return max((int) $f['date_moved'], (int) $f['date_modification'], (int) ($f['last_comment'] ?? 0));
    }

    public static function blockedSince(array $f): int
    {
        return isset($f['meta']['blocked_on']) ? (int) $f['meta']['blocked_on']['changed_on'] : (int) $f['date_modification'];
    }

    public static function hasWayfinder(array $tags): bool
    {
        foreach ($tags as $t) {
            if (str_starts_with($t, 'wayfinder:')) {
                return true;
            }
        }
        return false;
    }

    public static function applies(string $appliesTo, string $ownerKind): bool
    {
        return match ($appliesTo) {
            'all' => true,
            'agents' => $ownerKind === 'agent',
            'unowned' => $ownerKind === 'none',
            default => false,
        };
    }

    public static function holds(string $key, array $f, WipCatalogue $cat, int $now, int $lookbackDays): bool
    {
        $open = (int) $f['is_active'] === 1;
        $ip = $open && $f['role'] === 'in_progress';
        $tags = $f['tags'];
        $meta = $f['meta'];
        $loc = $meta['loc_state']['value'] ?? null;

        switch ($key) {
            case 'stale':
                return $ip && $loc !== 'live'
                    && $now - self::lastActivity($f) >= $cat->threshold('stale_days') * 86400;
            case 'merged':
                return $f['role'] !== 'done' && ($meta['pr_state']['value'] ?? null) === 'merged';
            case 'donesubs':
                return $open && $f['sub_total'] > 0 && $f['sub_done'] === $f['sub_total']
                    && ! in_array('hold', $tags, true);
            case 'offboard':
                return $open && $f['role'] === 'ready' && ($f['sub_done'] + $f['sub_prog']) > 0;
            case 'ended':
                return $ip && $loc === 'ended';
            case 'mismatch':
                return ! $open && $f['has_done'] && $f['role'] !== 'done'
                    && (int) $f['date_completed'] >= $now - $lookbackDays * 86400
                    && ! self::hasWayfinder($tags);
            case 'blocked':
                return $open && array_intersect(self::BLOCK_TAGS, $tags) !== [];
            case 'unverified':
                return $open && $loc === 'unverified'
                    && $now - (int) ($meta['loc_last_seen']['value'] ?? 0) > $cat->threshold('unverified_hours') * 3600;
            case 'noowner':
                return $ip && (int) $f['owner_id'] === 0 && $f['agent_member'];
            case 'timemiss':
                $endedAt = $f['role'] === 'done'
                    ? max((int) $f['date_completed'], (int) $f['date_moved'])
                    : ($loc === 'ended' ? (int) $meta['loc_state']['changed_on'] : 0);
                return $endedAt > 0 && ! isset($meta['time_backfilled_at'])
                    && $now - $endedAt > $cat->threshold('timemiss_hours') * 3600;
        }
        return false;
    }

    /**
     * @param string[] $available flag keys that may be evaluated (WipCatalogue::available() in production)
     * @return array{flags: string[], rank_weight: int, fix: ?string, sort_ts: int}
     */
    public static function evaluate(array $f, WipCatalogue $cat, int $now, int $lookbackDays, array $available): array
    {
        $keys = [];
        foreach ($cat->flags() as $key => $def) {
            if (in_array($key, $available, true)
                && self::applies($def['applies_to'], $f['owner_kind'])
                && self::holds($key, $f, $cat, $now, $lookbackDays)) {
                $keys[] = $key;
            }
        }
        // Highest weight first; equal weights keep catalogue order.
        $order = array_flip(array_keys($cat->flags()));
        usort($keys, fn ($a, $b) => [$cat->weight($b), $order[$a]] <=> [$cat->weight($a), $order[$b]]);
        $top = $keys[0] ?? null;

        return [
            'flags' => $keys,
            'rank_weight' => $top === null ? 0 : $cat->weight($top),
            'fix' => $top,
            'sort_ts' => $top === 'blocked' ? self::blockedSince($f) : self::lastActivity($f),
        ];
    }

    /** Highest rank_weight first, then oldest sort_ts, then lowest task_id. */
    public static function rank(array $rows): array
    {
        usort($rows, fn ($a, $b) => [$b['rank_weight'], $a['sort_ts'], $a['task_id']] <=> [$a['rank_weight'], $b['sort_ts'], $b['task_id']]);
        return $rows;
    }
}
