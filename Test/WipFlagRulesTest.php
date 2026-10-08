<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Agents\Model\WipCatalogue;
use Kanboard\Plugin\Agents\Model\WipFlagRules;

class WipFlagRulesTest extends Base
{
    const NOW = 1791300000;
    const DAY = 86400;

    private WipCatalogue $cat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cat = new WipCatalogue();
    }

    /** A fresh, unflagged, open, agent-owned In-progress ticket; override per test. */
    private function facts(array $o = []): array
    {
        return array_merge([
            'task_id' => 1, 'is_active' => 1, 'role' => 'in_progress', 'has_done' => true,
            'owner_id' => 10, 'owner_kind' => 'agent', 'agent_member' => true,
            'date_moved' => self::NOW - 3600, 'date_modification' => self::NOW - 3600, 'date_completed' => 0,
            'last_comment' => null, 'sub_total' => 0, 'sub_done' => 0, 'sub_prog' => 0,
            'tags' => [], 'meta' => [],
        ], $o);
    }

    private function meta(string $value, int $changedOn = self::NOW): array
    {
        return ['value' => $value, 'changed_on' => $changedOn];
    }

    private function flags(array $o, ?array $available = null): array
    {
        return WipFlagRules::evaluate($this->facts($o), $this->cat, self::NOW, 30, $available ?? WipCatalogue::KEYS)['flags'];
    }

    public function testFreshTicketHasNoFlags(): void
    {
        $this->assertSame([], $this->flags([]));
    }

    public function testStaleAtThreeDaysOfNoActivity(): void
    {
        $old = self::NOW - 3 * self::DAY;
        $this->assertSame(['stale'], $this->flags(['date_moved' => $old, 'date_modification' => $old]));
        $this->assertSame([], $this->flags(['date_moved' => $old + 60, 'date_modification' => $old]));
    }

    public function testStaleCountsTheNewestCommentAsActivity(): void
    {
        $old = self::NOW - 10 * self::DAY;
        $this->assertSame([], $this->flags(['date_moved' => $old, 'date_modification' => $old, 'last_comment' => self::NOW - 60]));
    }

    public function testStaleNeverForLiveSessionOrOutsideInProgress(): void
    {
        $old = self::NOW - 10 * self::DAY;
        $this->assertSame([], $this->flags(['date_moved' => $old, 'date_modification' => $old, 'meta' => ['loc_state' => $this->meta('live')]]));
        $this->assertSame([], $this->flags(['date_moved' => $old, 'date_modification' => $old, 'role' => 'ready']));
    }

    public function testMergedNeedsPrStateMergedOutsideDone(): void
    {
        $this->assertSame(['merged'], $this->flags(['meta' => ['pr_state' => $this->meta('merged')]]));
        $this->assertSame([], $this->flags(['role' => 'done', 'meta' => ['pr_state' => $this->meta('merged')]]));
    }

    public function testDonesubsWhenEverySubtaskDoneUnlessHold(): void
    {
        $this->assertSame(['donesubs'], $this->flags(['sub_total' => 2, 'sub_done' => 2]));
        $this->assertSame([], $this->flags(['sub_total' => 2, 'sub_done' => 1]));
        $this->assertSame([], $this->flags(['sub_total' => 0, 'sub_done' => 0]));
        $this->assertSame([], $this->flags(['sub_total' => 2, 'sub_done' => 2, 'tags' => ['hold']]));
    }

    public function testOffboardInReadyWithWorkedSubtasks(): void
    {
        $this->assertSame(['offboard'], $this->flags(['role' => 'ready', 'sub_total' => 3, 'sub_prog' => 1]));
        $this->assertSame([], $this->flags(['role' => 'ready', 'sub_total' => 3]));
    }

    public function testEndedIsInProgressWithLocationEnded(): void
    {
        $this->assertSame(['ended'], $this->flags(['meta' => ['loc_state' => $this->meta('ended')]]));
    }

    public function testMismatchClosedOutsideDoneWithinLookback(): void
    {
        $closed = ['is_active' => 0, 'role' => 'in_progress', 'date_completed' => self::NOW - 5 * self::DAY];
        $this->assertSame(['mismatch'], $this->flags($closed));
        $this->assertSame([], $this->flags(['date_completed' => self::NOW - 31 * self::DAY] + $closed));
        $this->assertSame([], $this->flags(['tags' => ['wayfinder:map']] + $closed));
        $this->assertSame([], $this->flags(['role' => 'done'] + $closed));
        $this->assertSame([], $this->flags(['has_done' => false] + $closed));
    }

    public function testMismatchHonoursTheLookbackParameter(): void
    {
        $closed = $this->facts(['is_active' => 0, 'date_completed' => self::NOW - 40 * self::DAY]);
        $this->assertSame(['mismatch'], WipFlagRules::evaluate($closed, $this->cat, self::NOW, 60, WipCatalogue::KEYS)['flags']);
    }

    public function testBlockedOnBlockedOrNeedsInfoTag(): void
    {
        $this->assertSame(['blocked'], $this->flags(['tags' => ['blocked']]));
        $this->assertSame(['blocked'], $this->flags(['tags' => ['needs-info']]));
        $this->assertSame([], $this->flags(['tags' => ['blocked'], 'is_active' => 0, 'role' => 'done']));
    }

    public function testUnverifiedAfter24HoursUnseen(): void
    {
        $meta = ['loc_state' => $this->meta('unverified'), 'loc_last_seen' => $this->meta((string) (self::NOW - 25 * 3600))];
        $this->assertSame(['unverified'], $this->flags(['meta' => $meta]));
        $meta['loc_last_seen'] = $this->meta((string) (self::NOW - 3600));
        $this->assertSame([], $this->flags(['meta' => $meta]));
    }

    public function testNoownerOnlyUnownedInProgressOnAnAgentProject(): void
    {
        $this->assertSame(['noowner'], $this->flags(['owner_id' => 0, 'owner_kind' => 'none']));
        $this->assertSame([], $this->flags(['owner_id' => 0, 'owner_kind' => 'none', 'agent_member' => false]));
        $this->assertSame([], $this->flags(['owner_id' => 0, 'owner_kind' => 'none', 'role' => 'ready']));
    }

    /** An agent-owned ticket moved to Done `$secs` ago, with a Location; `$meta` adds metadata. */
    private function doneAgo(int $secs, array $meta = []): array
    {
        $at = self::NOW - $secs;
        return ['role' => 'done', 'date_moved' => $at, 'date_modification' => $at,
                'meta' => ['loc_session_id' => $this->meta('sess-1')] + $meta];
    }

    public function testTimemissAfterDoneWithoutBackfillStamp(): void
    {
        $this->assertSame(['timemiss'], $this->flags($this->doneAgo(2 * self::DAY)));
        $this->assertSame([], $this->flags($this->doneAgo(2 * self::DAY, ['time_backfilled_at' => $this->meta((string) self::NOW)])));
    }

    public function testTimemissClearedByAStampAtOrAfterEndedAt(): void
    {
        $endedAt = self::NOW - 2 * self::DAY;
        $this->assertSame([], $this->flags($this->doneAgo(2 * self::DAY, ['time_backfilled_at' => $this->meta((string) $endedAt)])));
    }

    public function testTimemissWhenTheStampPredatesEndedAt(): void
    {
        $stale = (string) (self::NOW - 2 * self::DAY - 1);
        $this->assertSame(['timemiss'], $this->flags($this->doneAgo(2 * self::DAY, ['time_backfilled_at' => $this->meta($stale)])));
    }

    public function testTimemissWhenTheStampIsNotNumeric(): void
    {
        $this->assertSame(['timemiss'], $this->flags($this->doneAgo(2 * self::DAY, ['time_backfilled_at' => $this->meta('garbage')])));
    }

    public function testTimemissNeedsALocation(): void
    {
        $f = $this->doneAgo(2 * self::DAY);
        unset($f['meta']['loc_session_id']);
        $this->assertSame([], $this->flags($f));
        $f['meta']['loc_session_id'] = $this->meta('');
        $this->assertSame([], $this->flags($f));
    }

    public function testTimemissWaitsOutTheWindow(): void
    {
        $this->assertSame([], $this->flags($this->doneAgo(23 * 3600)));
    }

    public function testTimemissWindowIsStrict(): void
    {
        $this->assertSame([], $this->flags($this->doneAgo(24 * 3600)));
        $this->assertSame(['timemiss'], $this->flags($this->doneAgo(24 * 3600 + 1)));
    }

    public function testTimemissOnAClosedTicketOutsideDone(): void
    {
        $at = self::NOW - 2 * self::DAY;
        $f = ['is_active' => 0, 'role' => 'in_progress', 'has_done' => true, 'date_completed' => $at,
              'date_moved' => $at, 'date_modification' => $at, 'meta' => ['loc_session_id' => $this->meta('sess-1')]];
        $this->assertContains('timemiss', $this->flags($f));
        $this->assertContains('mismatch', $this->flags($f));
    }

    public function testTimemissWhenTheLocationEnded(): void
    {
        $meta = ['loc_session_id' => $this->meta('sess-1'), 'loc_state' => $this->meta('ended', self::NOW - 2 * self::DAY)];
        $flags = $this->flags(['meta' => $meta]);
        $this->assertContains('timemiss', $flags);
        $this->assertContains('ended', $flags);
    }

    public function testTimemissIgnoresHumanTickets(): void
    {
        $this->assertNotContains('timemiss', $this->flags(['owner_id' => 2, 'owner_kind' => 'human'] + $this->doneAgo(2 * self::DAY)));
    }

    public function testHumanTicketsGetOnlyPositionFlags(): void
    {
        $old = self::NOW - 10 * self::DAY;
        $f = ['owner_id' => 2, 'owner_kind' => 'human', 'date_moved' => $old, 'date_modification' => $old,
              'meta' => ['loc_state' => $this->meta('ended'), 'pr_state' => $this->meta('merged')]];
        $this->assertSame(['stale'], $this->flags($f));
    }

    public function testUnavailableFlagsAreNeverRaised(): void
    {
        $f = ['meta' => ['pr_state' => $this->meta('merged'), 'loc_state' => $this->meta('ended')]];
        $this->assertSame([], $this->flags($f, $this->cat->available()));
    }

    public function testRankIsTheHighestWeightAndFixIsTheTopFlag(): void
    {
        $old = self::NOW - 10 * self::DAY;
        $ev = WipFlagRules::evaluate($this->facts(['owner_id' => 0, 'owner_kind' => 'none', 'date_moved' => $old, 'date_modification' => $old]),
            $this->cat, self::NOW, 30, WipCatalogue::KEYS);
        $this->assertSame(['stale', 'noowner'], $ev['flags']);
        $this->assertSame(5, $ev['rank_weight']);
        $this->assertSame('stale', $ev['fix']);
        $this->assertSame($old, $ev['sort_ts']);
    }

    public function testBlockedRowsSortByWhenBlockedOnChanged(): void
    {
        $ev = WipFlagRules::evaluate($this->facts(['tags' => ['blocked'], 'meta' => ['blocked_on' => $this->meta('q', self::NOW - 999)]]),
            $this->cat, self::NOW, 30, WipCatalogue::KEYS);
        $this->assertSame(self::NOW - 999, $ev['sort_ts']);
    }

    public function testRankOrdersWeightThenOldestThenTaskId(): void
    {
        $rows = [
            ['task_id' => 1, 'rank_weight' => 2, 'sort_ts' => 100],
            ['task_id' => 2, 'rank_weight' => 5, 'sort_ts' => 900],
            ['task_id' => 3, 'rank_weight' => 5, 'sort_ts' => 100],
            ['task_id' => 4, 'rank_weight' => 5, 'sort_ts' => 100],
            ['task_id' => 5, 'rank_weight' => 0, 'sort_ts' => 1],
        ];
        $this->assertSame([3, 4, 2, 1, 5], array_column(WipFlagRules::rank($rows), 'task_id'));
    }
}
