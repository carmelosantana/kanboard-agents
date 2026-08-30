<div class="page-header"><h2><?= t('Agent created') ?></h2></div>
<div class="alert alert-error"><?= t('Copy the token now — it is shown only once.') ?></div>
<p><strong><?= t('Username') ?>:</strong> <?= $this->text->e($result['username']) ?></p>
<p><strong><?= t('Personal API token') ?>:</strong> <code><?= $this->text->e($result['token']) ?></code></p>
<h3><?= t('MCP environment') ?></h3>
<pre>KANBOARD_API_ENDPOINT=<?= $this->text->e($endpoint) ?>

KANBOARD_AUTH_METHOD=user_token
KANBOARD_USERNAME=<?= $this->text->e($result['username']) ?>

KANBOARD_API_KEY=<?= $this->text->e($result['token']) ?>

KANBOARD_USER_APP_ROLES=app-user</pre>
<p><?= $this->url->link(t('Back to My Agents'), 'AgentController', 'index', ['plugin' => 'Agents']) ?></p>
