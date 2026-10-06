<?php
// Shared board-building helpers for the WIP tests. Not a test case (PHPUnit only collects *Test.php).

use Kanboard\Plugin\Agents\Model\AgentTable;

trait WipFixture
{
    protected function bootAgents(): void
    {
        require_once __DIR__.'/../Schema/Sqlite.php';
        \Kanboard\Plugin\Agents\Schema\version_1($this->container['db']->getConnection());
    }

    protected function user(string $username, string $role = 'app-user'): int
    {
        return (int) $this->container['userModel']->create(['username' => $username, 'password' => 'x1234567', 'role' => $role]);
    }

    protected function agentOf(int $ownerId, string $username, string $kind = 'claude'): int
    {
        $id = $this->user($username, 'app-manager');
        (new AgentTable($this->container))->insert($ownerId, $id, $kind);
        return $id;
    }

    protected function actAs(int $uid): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($uid));
    }

    /** Drop the session: what the jsonrpc app token looks like (isLogged() false, getId() 0). */
    protected function actAsAppToken(): void
    {
        session_remove('user');
    }

    /** A project with columns Backlog, Ready, In progress, Done and the given members [uid => role]. */
    protected function project(string $name, array $members = []): int
    {
        $pid = (int) $this->container['projectModel']->create(['name' => $name]);
        $this->container['columnModel']->update($this->col($pid, 'Work in progress'), 'In progress');
        foreach ($members as $uid => $role) {
            $this->container['projectUserRoleModel']->addUser($pid, $uid, $role);
        }
        return $pid;
    }

    protected function col(int $pid, string $title): int
    {
        return (int) array_search($title, $this->container['columnModel']->getList($pid), true);
    }

    protected function task(int $pid, string $column, int $ownerId = 0, string $title = 'T'): int
    {
        return (int) $this->container['taskCreationModel']->create([
            'project_id' => $pid, 'title' => $title, 'column_id' => $this->col($pid, $column), 'owner_id' => $ownerId,
        ]);
    }

    /** Write task columns directly (timestamps, is_active) after everything else, so no event re-stamps them. */
    protected function touch(int $tid, array $fields): void
    {
        $this->container['db']->table('tasks')->eq('id', $tid)->update($fields);
    }

    protected function tags(int $pid, int $tid, array $names): void
    {
        $this->container['taskTagModel']->save($pid, $tid, $names);
    }

    protected function subtask(int $tid, string $title, int $status): void
    {
        $this->container['subtaskModel']->create(['task_id' => $tid, 'title' => $title, 'status' => $status]);
    }

    protected function comment(int $tid, string $text, int $at): void
    {
        $id = $this->container['commentModel']->create(['task_id' => $tid, 'user_id' => 0, 'comment' => $text]);
        $this->container['db']->table('comments')->eq('id', $id)->update(['date_creation' => $at]);
    }
}
