<?php
// Engine portability probe for the WIP passes (not a PHPUnit test; the unit suite is SQLite-only).
// Run inside the kanboard/kanboard image against a throwaway MariaDB or Postgres; see the plan's Task 10.
//   php portability-probe.php            flags + fix on a fresh database; prints timings; "OK <driver>"
//   php portability-probe.php seed-v1    a v1-schema database: core + agents table at v1, plugin NOT loaded,
//                                        tasks moved by an agent and by a human (project_activities rows)
//   php portability-probe.php upgrade    load the plugin at v3 on that database (Schema\version_2 runs
//                                        ProvenanceBackfill, version_3 ProvenanceRelabel), check the stamps,
//                                        then relabel a late-adopted agent's human stamp; "OK upgrade <driver>"
// WIP_PROBE_SCALE (default 1000) extra tasks are added after the checks to time getWipFlags at scale.
$mode = $argv[1] ?? 'flags';
if ($mode === 'seed-v1') {
    // No plugins: the loader must not run the Agents schema past v1.
    @mkdir('/tmp/no-plugins');
    define('PLUGINS_DIR', '/tmp/no-plugins');
}
require '/var/www/app/app/common.php';

use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Agents\Model\AgentTable;
use Kanboard\Plugin\Agents\Model\WipCatalogue;
use Kanboard\Plugin\Agents\Model\WipFixService;
use Kanboard\Plugin\Agents\Model\WipFlagRules;
use Kanboard\Plugin\Agents\Model\WipQuery;
use Kanboard\Plugin\Agents\Model\WipScope;
use Kanboard\Plugin\Agents\Model\WipView;

$c = $container;
$u = $c['userModel'];

function probe_fail(string $what, $detail): void
{
    fwrite(STDERR, 'FAIL '.DB_DRIVER.' '.$what."\n".json_encode($detail, JSON_UNESCAPED_UNICODE)."\n");
    exit(1);
}

if ($mode === 'seed-v1') {
    require_once __DIR__.'/../Schema/'.ucfirst(DB_DRIVER).'.php';
    $pdo = $c['db']->getConnection();
    \Kanboard\Plugin\Agents\Schema\version_1($pdo);
    $c['db']->getDriver()->upsert('plugin_schema_versions', 'plugin', 'version', ['agents' => 1]);
    $carmelo = $u->create(['username' => 'carmelo', 'password' => 'x1234567', 'role' => 'app-user']);
    $claude = $u->create(['username' => 'carmelo.claude', 'password' => 'x1234567', 'role' => 'app-manager']);
    $pdo->exec("INSERT INTO agents (owner_user_id, agent_user_id, kind, created_at) VALUES ($carmelo, $claude, 'claude', 0)");
    $pid = $c['projectModel']->create(['name' => 'P']);
    $cols = array_flip($c['columnModel']->getList($pid));
    $byAgent = $c['taskCreationModel']->create(['project_id' => $pid, 'title' => 'moved-by-agent', 'column_id' => $cols['Ready']]);
    $byHuman = $c['taskCreationModel']->create(['project_id' => $pid, 'title' => 'moved-by-human', 'column_id' => $cols['Ready']]);
    $unmoved = $c['taskCreationModel']->create(['project_id' => $pid, 'title' => 'never-moved', 'column_id' => $cols['Ready']]);
    // Real moves through core: NotificationSubscriber writes the project_activities rows.
    $c['userSession']->initialize($u->getById($claude));
    $c['taskPositionModel']->movePosition($pid, $byAgent, $cols['Work in progress'], 1, (int) $c['taskFinderModel']->getById($byAgent)['swimlane_id']);
    $c['userSession']->initialize($u->getById($carmelo));
    $c['taskPositionModel']->movePosition($pid, $byHuman, $cols['Work in progress'], 1, (int) $c['taskFinderModel']->getById($byHuman)['swimlane_id']);
    $c['db']->table('project_activities')->eq('task_id', $byAgent)->update(['date_creation' => 1700000000]);
    $c['db']->table('project_activities')->eq('task_id', $byHuman)->update(['date_creation' => 1700000100]);
    $acts = $c['db']->table('project_activities')->columns('task_id', 'creator_id', 'event_name', 'date_creation')->in('task_id', [$byAgent, $byHuman])->asc('id')->findAll();
    $meta = $c['db']->table('task_has_metadata')->count();
    $ver = $c['db']->table('plugin_schema_versions')->eq('plugin', 'agents')->findOneColumn('version');
    echo json_encode(['agents_schema' => (int) $ver, 'agent_uid' => $claude, 'human_uid' => $carmelo,
        'tasks' => ['moved-by-agent' => $byAgent, 'moved-by-human' => $byHuman, 'never-moved' => $unmoved],
        'project_activities' => $acts, 'task_has_metadata_rows' => $meta]), "\n";
    if ((int) $ver !== 1 || count($acts) < 2 || $meta !== 0) {
        probe_fail('seed-v1', 'expected agents schema 1, >=2 move activities, no metadata');
    }
    echo 'OK seed-v1 '.DB_DRIVER."\n";
    exit(0);
}

if ($mode === 'upgrade') {
    // common.php has already run the loader: Schema\version_2 and version_3 ran (or failed and were logged) by now.
    $loaded = array_keys($c['pluginLoader']->getPlugins());
    $ver = (int) $c['db']->table('plugin_schema_versions')->eq('plugin', 'agents')->findOneColumn('version');
    $got = [];
    foreach ($c['db']->table('tasks')->columns('id', 'title')->asc('id')->findAll() as $t) {
        $m = $c['taskMetadataModel']->getAll((int) $t['id']);
        $got[$t['title']] = ['kind' => $m['moved_by_kind'] ?? null, 'uid' => $m['moved_by_uid'] ?? null, 'at' => $m['moved_at'] ?? null];
    }
    // moved_at must be the activity's own timestamp, not the upgrade time.
    $actAt = function (string $title) use ($c) {
        $tid = (int) $c['db']->table('tasks')->eq('title', $title)->findOneColumn('id');
        return (string) $c['db']->table('project_activities')->eq('task_id', $tid)->eq('event_name', 'task.move.column')->desc('id')->findOneColumn('date_creation');
    };
    $agentUid = (string) $u->getIdByUsername('carmelo.claude');
    $humanUid = (string) $u->getIdByUsername('carmelo');
    echo json_encode(['plugin_loaded' => in_array('Agents', $loaded, true), 'agents_schema' => $ver, 'stamps' => $got]), "\n";
    $checks = [
        'loaded' => in_array('Agents', $loaded, true),
        'schema' => $ver === 3,
        'agent' => $got['moved-by-agent']['kind'] === 'agent' && $got['moved-by-agent']['uid'] === $agentUid && $got['moved-by-agent']['at'] === $actAt('moved-by-agent'),
        'human' => $got['moved-by-human']['kind'] === 'human' && $got['moved-by-human']['uid'] === $humanUid && $got['moved-by-human']['at'] === $actAt('moved-by-human'),
        'unmoved' => $got['never-moved'] === ['kind' => null, 'uid' => null, 'at' => null],
    ];
    foreach ($checks as $name => $ok) {
        if (! $ok) {
            probe_fail('upgrade '.$name, $got);
        }
    }
    // v3 on this engine: a user stamped human, adopted into the roster afterwards, relabels to agent once.
    $late = $u->create(['username' => 'late.agent', 'password' => 'x1234567', 'role' => 'app-user']);
    $lateTask = (int) $c['db']->table('tasks')->eq('title', 'never-moved')->findOneColumn('id');
    $c['taskMetadataModel']->save($lateTask, ['moved_by_uid' => (string) $late, 'moved_by_kind' => 'human', 'moved_at' => '1700000200']);
    $pdo = $c['db']->getConnection();
    $pdo->exec("INSERT INTO agents (owner_user_id, agent_user_id, kind, created_at) VALUES ((SELECT id FROM users WHERE username = 'carmelo'), $late, 'claude', 0)");
    $first = \Kanboard\Plugin\Agents\Model\ProvenanceRelabel::run($pdo);
    $second = \Kanboard\Plugin\Agents\Model\ProvenanceRelabel::run($pdo);
    $kinds = array_map(fn ($t) => $c['taskMetadataModel']->get((int) $c['db']->table('tasks')->eq('title', $t)->findOneColumn('id'), 'moved_by_kind'), ['moved-by-agent', 'moved-by-human', 'never-moved']);
    echo json_encode(['relabel' => [$first, $second], 'kinds' => $kinds]), "\n";
    if ($first !== 1 || $second !== 0 || $kinds !== ['agent', 'human', 'agent']) {
        probe_fail('upgrade relabel', ['relabel' => [$first, $second], 'kinds' => $kinds]);
    }
    echo 'OK upgrade '.DB_DRIVER."\n";
    exit(0);
}

$carmelo = $u->create(['username' => 'carmelo', 'password' => 'x1234567', 'role' => 'app-user']);
$claude = $u->create(['username' => 'carmelo.claude', 'password' => 'x1234567', 'role' => 'app-manager']);
(new AgentTable($c))->insert($carmelo, $claude, 'claude');
$pid = $c['projectModel']->create(['name' => 'P']);
$cols = array_flip($c['columnModel']->getList($pid));
$c['columnModel']->update($cols['Work in progress'], 'In progress');
$c['projectUserRoleModel']->addUser($pid, $carmelo, Role::PROJECT_MEMBER);
$c['projectUserRoleModel']->addUser($pid, $claude, Role::PROJECT_MEMBER);
$ip = $cols['Work in progress'];
$mk = fn ($title, $col, $owner) => $c['taskCreationModel']->create(['project_id' => $pid, 'title' => $title, 'column_id' => $col, 'owner_id' => $owner]);

$stale = $mk('stale', $ip, $claude);
$subs = $mk('subs', $ip, $carmelo);
$c['subtaskModel']->create(['task_id' => $subs, 'title' => 'a', 'status' => 2]);
$c['subtaskModel']->create(['task_id' => $subs, 'title' => '⏱ carried from subtask 1', 'status' => 0]);
$closed = $mk('closed', $ip, $carmelo);
$blocked = $mk('blocked', $cols['Ready'], $carmelo);
$c['taskTagModel']->save($pid, $blocked, ['Blocked', 'Hold ']);
$c['commentModel']->create(['task_id' => $stale, 'user_id' => 0, 'comment' => '⏱ reconciler: clamp']);
$c['taskMetadataModel']->save($stale, ['moved_by_kind' => 'agent', 'noise' => 'x']);
$old = time() - 10 * 86400;
$c['db']->table('tasks')->eq('id', $stale)->update(['date_moved' => $old, 'date_modification' => $old]);
$c['db']->table('tasks')->eq('id', $closed)->update(['is_active' => 0, 'date_completed' => time() - 3600]);

$c['userSession']->initialize($u->getById($carmelo));
$env = (new WipView($c))->flags();
$got = [];
foreach ($env['rows'] as $r) {
    $got[$r['title']] = [$r['flags'], $r['owner_kind'], $r['evidence']['moved_by_kind']];
}
$want = [
    'stale' => [['stale'], 'agent', 'agent'],
    'subs' => [['donesubs'], 'human', null],
    'closed' => [['mismatch'], 'human', null],
    'blocked' => [['blocked'], 'human', null],
];
$t = $c['taskFinderModel']->getById($closed);
$fix = (new WipFixService($c))->apply($closed, 'move_to_done', (int) $t['date_modification']);
$after = $c['taskFinderModel']->getById($closed);
$comments = $c['commentModel']->getAll($closed);

$checks = [
    'rows' => $got === $want,
    'summary' => $env['summary']['flagged'] === 4 && $env['summary']['merged'] === null,
    'fix' => $fix['ok'] === true,
    'moved' => (int) $after['column_id'] === (int) $cols['Done'] && (int) $after['is_active'] === 0,
    'comment' => end($comments)['comment'] === '🧭 WIP view: Move to Done (Closed, not in Done) by carmelo',
];
foreach ($checks as $name => $ok) {
    if (! $ok) {
        probe_fail($name, ['rows' => $got, 'summary' => $env['summary'], 'fix' => $fix]);
    }
}

// Timing: median of 5 runs of the full getWipFlags build and of the single-task path that
// applyWipFix uses (scope resolve + columns + facts($taskId) + evaluate).
$time = function (callable $fn): float {
    $ms = [];
    for ($i = 0; $i < 5; $i++) {
        $t0 = hrtime(true);
        $fn();
        $ms[] = (hrtime(true) - $t0) / 1e6;
    }
    sort($ms);
    return round($ms[2], 1);
};
$single = function () use ($c, $pid, $subs) {
    $cat = new WipCatalogue();
    $now = time();
    $lookback = WipView::MAX_LOOKBACK_DAYS; // as WipFixService::apply re-evaluates
    $scope = (new WipScope($c))->resolve(null, 'all', [$pid]);
    $columns = (new WipQuery($c))->columns([$pid]);
    $f = (new WipView($c))->facts($scope, $columns, $now - $lookback * 86400, $subs);
    WipFlagRules::evaluate($f[0], $cat, $now, $lookback, $cat->available());
};
$report = function (string $label) use ($c, $time, $single) {
    $n = $c['db']->table('tasks')->count();
    $full = $time(fn () => (new WipView($c))->flags());
    $one = $time($single);
    $sum = (new WipView($c))->flags()['summary'];
    echo 'TIMING '.DB_DRIVER.' '.$label.' tasks='.$n.' in_progress='.$sum['in_progress'].' flagged='.$sum['flagged']
        .' getWipFlags_full_ms='.$full.' single_task_ms='.$one."\n";
};
$report('probe');

$scale = (int) (getenv('WIP_PROBE_SCALE') ?: 1000);
$c['db']->startTransaction();
for ($i = 0; $i < $scale; $i++) {
    $tid = $mk('bulk '.$i, $i % 3 === 0 ? $cols['Ready'] : $ip, $i % 2 === 0 ? $claude : $carmelo);
    if ($i % 4 === 0) {
        $c['subtaskModel']->create(['task_id' => $tid, 'title' => 's', 'status' => 2]);
    }
    if ($i % 5 === 0) {
        $c['commentModel']->create(['task_id' => $tid, 'user_id' => $carmelo, 'comment' => 'c']);
    }
}
$c['db']->closeTransaction();
$report('scaled');

echo 'OK '.DB_DRIVER."\n";
