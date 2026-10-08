# Plan: switch on the `timemiss` WIP flag (Kanboard #5808)

Spec: `docs/superpowers/specs/2026-10-06-wip-view.md` (flag catalogue row `timemiss`), plus the
agent-ergonomics-v2 spec's time back-fill section: "A host without it installed shows `Time missing`."

## Context

Plan 3 (kanboard-mcp) ships `bin/kanboard-time-backfill.sh`. On a successful back-fill of a ticket it
writes task metadata `time_backfilled_at` = Unix seconds of that run (string of digits). It back-fills
only agent-owned tickets that have `loc_session_id` (otherwise it skips with `no-location` and never
stamps). It runs nightly and at Done: the reconciler re-runs it for a closed ticket whose stamp is
empty, non-numeric, or older than `date_completed`.

`catalogue.json` still marks `timemiss` `"requires": "backfill"`, so `WipCatalogue::unavailable()`
lists it and the flag is never evaluated. `WipFlagRules::holds('timemiss', …)` already exists.

## Decision: what "Time missing" means

An agent-owned ticket (`applies_to: agents`, unchanged) shows `Time missing` when ALL hold:

1. It has a Location: `meta.loc_session_id` is present and non-empty. Without it the back-fill has no
   session to count and can never clear the flag, so the flag would be permanent noise.
2. Its work has ended, at `endedAt`:
   - Done column (`role === 'done'`) **or closed** (`is_active === 0`): `max(date_completed, date_moved)`;
   - otherwise Location `loc_state === 'ended'`: `meta.loc_state.changed_on`;
   - otherwise it has not ended: no flag.
3. `now - endedAt > timemiss_hours * 3600` (24 h: the at-Done run plus one nightly window).
4. The back-fill has not covered it: `time_backfilled_at` is absent or not all digits, or — closed
   tickets only (`is_active === 0`) — its integer value `< date_completed` (the reconciler's own
   staleness test; an open ticket is cleared by any numeric stamp, since nothing re-stamps it).

Fix text stays `Install back-fill`, weight 1, `oneclick: false`.

## Global constraints

- TDD: failing test first. Run `./testing/run-plugin-tests.sh Agents` from
  `~/Projects/Kanboard/kanboard-plugins` (the worktree must be what that runner tests — check how it
  resolves the plugin dir; if it tests the main checkout, run the worktree's tests the equivalent way
  and say how in the report).
- Only `timemiss` changes availability. `merged`, `ended`, `unverified` keep their `requires`.
- `catalogue.json` stays compact one-flag-per-line as now; only the `timemiss` line's `requires`
  changes to `null`.
- Version 0.3.3 aligned in `plugin.json`, `Plugin::getPluginVersion()`, `Test/PluginVersionTest.php`
  (rename the test to `testVersionIsAlignedAt033`), README gets a `## 0.3.3 — Time missing on` note
  above `## 0.3.2` stating the definition in one or two bullets.
- No attribution lines in commits. Commit messages reference `(#5808)`.

## Task 1: Enable and sharpen `timemiss`

Files: `catalogue.json`, `Model/WipFlagRules.php`, `Test/WipFlagRulesTest.php`,
`Test/WipCatalogueTest.php`, `Test/WipViewTest.php`, `docs/superpowers/specs/2026-10-06-wip-view.md`
(update the `timemiss` row's rule text and `requires` cell to `—`/null and the `unavailable_flags`
example), `README.md` (line ~30 mentions time back-fill as not-yet-existing data: drop it there),
`plugin.json`, `Plugin.php`, `Test/PluginVersionTest.php`.

Tests to add in `WipFlagRulesTest` (agent-owned facts, `loc_session_id` present unless stated):
- Done 2 days ago, no stamp → `['timemiss']`.
- Done 2 days ago, numeric stamp ≥ endedAt → `[]`.
- Done 2 days ago, numeric stamp < endedAt (back-filled before a reopen) → `['timemiss']`.
- Done 2 days ago, stamp `"garbage"` → `['timemiss']`.
- Done 2 days ago, no `loc_session_id` → `[]`.
- Done 23 h ago, no stamp → `[]` (inside the window).
- Closed in a non-Done column 2 days ago (`is_active` 0, `role` 'in_progress', `has_done` true,
  `date_completed` set) → includes `timemiss` (alongside `mismatch`).
- Open In-progress, `loc_state` ended 2 days ago, no stamp → includes `timemiss` (alongside `ended`).
- Human-owned Done ticket, no stamp → no `timemiss`.
Update the existing `testTimemissAfterDoneWithoutBackfillStamp` so it carries `loc_session_id`.

`WipCatalogueTest` / `WipViewTest`: `unavailable()` / `unavailable_flags` become
`['merged', 'ended', 'unverified']`; the null-count assertion loop drops `timemiss`; add an assertion
that `timemiss` has an integer count in the summary.

## Task 2 (controller, not SDD): release + marketplace lockstep

Release like v0.3.2; re-vendor `catalogue.json` into the marketplace kanboard plugin, update the
pinned hash in `skills/wip/SKILL.md` and `test/test_kanboard_wip.sh`, bump the plugin version; bump
ModMenu directory; evidence comment on #5808.
