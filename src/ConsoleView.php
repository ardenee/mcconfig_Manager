<?php
// Rendered only for authenticated users with an existing, selected Minecraft server.
$dsmConsole=mc_platform()==='dsm'&&($server['control_mode']??'')==='dsm-on-demand';
if (!$dsmConsole) console_subscribe($selected);
$snapshot=$dsmConsole?['output'=>'','source'=>'DSM Container Manager','updated'=>0]:console_snapshot($selected);
$consoleJob=$_GET['job']??'';
$consoleJob=is_string($consoleJob)&&preg_match('/^[a-f0-9]{24}$/D',$consoleJob)?$consoleJob:'';
$jobStatus=$consoleJob!==''?console_recent_job($selected,$consoleJob):null;
?>
<section class="panel console-panel" data-console-server="<?= h($selected) ?>" data-console-job="<?= h($consoleJob) ?>">
  <div class="panel-title">
    <div><h2><?= h($server['name']??'Minecraft') ?> — Server console</h2>
      <p class="muted">Send Minecraft commands to this server only. This is not a shell or Docker terminal.</p></div>
    <button class="subtle" type="button" data-console-refresh>Refresh output</button>
  </div>
  <?php if ($dsmConsole): ?>
    <div class="alert warning">This server uses one-time DSM authorization. Its command console is managed through Synology Container Manager, not the local worker.</div>
  <?php elseif (!manager_online()): ?>
    <div class="alert warning">The local mcmanager worker is offline. Start it to send console commands or view live output.</div>
  <?php endif; ?>
  <?php if ($dsmConsole): ?>
    <p class="hint">Use DSM Container Manager to inspect container logs. mcmanager does not have unattended command access to this container.</p>
  <?php elseif (!is_container($server)): ?>
    <p class="hint">Supervised native servers use their process console. Existing Java servers can use localhost-only RCON if enabled and configured in server.properties. External native Bedrock requires a supervised process.</p>
  <?php elseif (is_bedrock($server)): ?>
    <p class="hint">Bedrock container commands use the image's send-command helper. Command responses appear in the container logs rather than in a direct response.</p>
  <?php else: ?>
    <p class="hint">Java container commands use the image's local RCON helper. RCON must be enabled inside the container.</p>
  <?php endif; ?>
  <div class="console-bar">
    <span data-console-state>Server: <?= h(manager_running_state($selected)['state']??'unknown') ?></span>
    <span data-console-source><?= h($snapshot['source']??'Waiting for output') ?></span>
    <span data-console-updated><?= !empty($snapshot['updated'])?'Updated '.h(date('H:i:s',(int)$snapshot['updated'])):'No output yet' ?></span>
  </div>
  <pre id="mc-console-output" class="console-output" role="log" aria-label="Minecraft server output" aria-live="off"><?= h($snapshot['output']??'') ?></pre>
  <?php if (!$dsmConsole): ?>
    <p class="hint">Recent server output is refreshed automatically while this page remains open. Output is truncated to the latest 24 KiB.<?php if (is_container($server)&&mc_platform()==='dsm'): ?> Full container logs remain in Container Manager.<?php else: ?> Full server logs are available under Server logs.<?php endif; ?></p>
  <form method="post" class="console-form" autocomplete="off">
    <?php hidden_csrf(); hidden_server($selected); ?>
    <input type="hidden" name="action" value="console_send">
    <label for="mc-console-command">Minecraft command</label>
    <div class="console-command-row">
      <span class="console-prompt" aria-hidden="true">&gt;</span>
      <input id="mc-console-command" type="text" name="command" maxlength="400" required
        spellcheck="false" autocapitalize="none" autocomplete="off"
        placeholder="say Hello players" aria-describedby="mc-console-command-note"
        <?= !manager_online()?'disabled':'' ?>>
      <button class="primary" type="submit" <?= !manager_online()?'disabled':'' ?>>Send command</button>
    </div>
    <p class="hint" id="mc-console-command-note">Examples: <code>list</code>, <code>say Server maintenance soon</code>, <code>time set day</code>, <code>save-all</code>. Commands use server administrator privileges and may change worlds or player permissions.</p>
  </form>
  <?php endif; ?>
  <div class="console-job-message" data-console-job-result role="status">
    <?php if ($jobStatus): ?>
      <?= h(ucfirst($jobStatus['state']).': '.($jobStatus['message']?:$jobStatus['result'])) ?>
    <?php endif; ?>
  </div>
</section>
