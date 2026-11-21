<?php
$workerInfo=manager_heartbeat();
$workerOnline=!empty($workerInfo['online']);
$recent=manager_recent_jobs();
$requestedJob=$_GET['job']??'';
$selectedJob=is_string($requestedJob) && preg_match('/^[a-f0-9]{24}$/D',$requestedJob)
    ? manager_result($requestedJob):null;
?>
<section class="panel">
  <div class="panel-title"><div><h2>Local management worker</h2>
    <p class="muted">Process and container operations are handled by a separate, restricted PHP CLI service.</p></div>
    <strong><?= $workerOnline?'Online':'Offline' ?></strong></div>
  <?php if (!$workerOnline): ?>
    <div class="alert warning">The background worker is not running. You can still edit configurations. On DSM, use Package Center to start the package and check the PHP CLI path and agent logs.</div>
  <?php else: ?><p class="hint">PHP <?= h($workerInfo['php']??'') ?> · Process ID <?= h($workerInfo['pid']??'') ?> · Last response <?= h(date('H:i:s',(int)$workerInfo['timestamp'])) ?></p><?php endif; ?>
  <form method="post" class="inline-form"><?php hidden_csrf(); ?>
    <button name="action" value="manager_diagnostics" class="subtle" <?= !$workerOnline?'disabled':'' ?>>Check hardware & Java</button>
    <button name="action" value="manager_discover" class="subtle" <?= !$workerOnline?'disabled':'' ?>>Discover existing servers</button>
  </form>
</section>

<section class="panel spaced">
  <h2>Java runtime management</h2>
  <p class="muted">Install or update an Eclipse Temurin JRE alongside other runtimes, without modifying DSM's system Java. The worker verifies the upstream SHA-256 checksum before extraction.</p>
  <form method="post" class="inline-form"><?php hidden_csrf(); ?><input type="hidden" name="action" value="manager_install_java">
    <label>Java LTS release <select name="major"><option value="25">Java 25 — Minecraft 26.x</option><option value="21">Java 21 — Minecraft 1.20.5–1.21.x</option><option value="17">Java 17 — older servers</option></select></label>
    <button class="primary" <?= !$workerOnline?'disabled':'' ?>>Install / update managed Java</button>
  </form>
  <p class="hint">Native installation requires Linux with a compatible x64 or ARM64 architecture. Existing Java installations remain untouched. Java inside containers is managed by their images.</p>
</section>

<?php if ($selectedJob): ?>
<section class="panel spaced">
  <div class="panel-title"><h2>Job result</h2><a class="subtle" href="?tab=manager&amp;job=<?= h($requestedJob) ?>">Refresh status</a></div>
  <div class="status-line"><span>Operation</span><strong><?= h($selectedJob['operation']??'') ?></strong></div>
  <div class="status-line"><span>State</span><strong><?= h($selectedJob['state']??'') ?></strong></div>
  <?php if (($selectedJob['state']??'')==='running'): ?>
    <div class="job-progress"><div style="width:<?= max(0,min(100,(int)($selectedJob['percent']??1))) ?>%"></div></div>
    <p class="muted"><?= h($selectedJob['message']??'Processing request') ?> — <?= h($selectedJob['percent']??'') ?>%</p>
  <?php endif; ?>
  <?php if (!empty($selectedJob['message'])): ?><div class="alert <?= ($selectedJob['state']??'')==='failed'?'danger':'warning' ?>"><?= h($selectedJob['message']) ?></div><?php endif; ?>
  <?php if (!empty($selectedJob['result']['system'])):
    $s=$selectedJob['result']['system']; ?>
    <div class="two-column">
      <div class="status-line"><span>Host platform</span><strong><?= h(($s['os']??'').' / '.($s['architecture']??'')) ?></strong></div>
      <div class="status-line"><span>Total RAM</span><strong><?= h(!empty($s['ram_total'])?round($s['ram_total']/1073741824,1).' GiB':'Unavailable') ?></strong></div>
      <div class="status-line"><span>Available RAM</span><strong><?= h(!empty($s['ram_available'])?round($s['ram_available']/1073741824,1).' GiB':'Unavailable') ?></strong></div>
      <div class="status-line"><span>Docker</span><strong><?= h($s['docker']??'') ?></strong></div>
      <div class="status-line"><span>Managed storage</span><code><?= h($s['instances_root']??'') ?></code></div>
      <div class="status-line"><span>Available disk</span><strong><?= h(isset($s['free_storage'])?round($s['free_storage']/1073741824,1).' GiB':'Unavailable') ?></strong></div>
    </div>
    <h3>Detected Java installations</h3>
    <?php foreach (($s['java']??[]) as $java): ?>
      <div class="status-line"><span><?= h($java['path']??'') ?></span><strong>Java <?= h($java['major']??'?') ?></strong></div>
    <?php endforeach; ?>
    <?php if (empty($s['java'])): ?><p class="muted">No Java executable found on the worker's PATH or standard installation paths.</p><?php endif; ?>
  <?php endif; ?>
  <?php if (!empty($selectedJob['result']['found'])): ?>
    <h3>Discovered servers</h3>
    <p class="muted">Scans are bounded and limited to configured storage locations. Discovery does not modify a server.</p>
    <?php foreach($selectedJob['result']['found'] as $found): ?>
      <div class="server-row"><div><strong><?= h($found['name']??'Server') ?></strong>
        <code><?= h($found['path']??'') ?></code>
        <small><?= h(ucfirst($found['edition']??'java').' · '.$found['deployment']) ?></small></div>
        <form method="post"><?php hidden_csrf(); ?><input type="hidden" name="action" value="manager_import">
          <?php foreach(['name','path','edition','deployment','software'] as $field): ?><input type="hidden" name="<?= h($field) ?>" value="<?= h($found[$field]??'') ?>"><?php endforeach; ?>
          <?php if (($found['deployment']??'')==='container'): ?><label>Existing container name <input name="container_name" required placeholder="minecraft-server"></label><?php endif; ?>
          <button class="subtle">Import</button>
        </form></div>
    <?php endforeach; ?>
  <?php endif; ?>
  <?php if (!empty($selectedJob['result']) && empty($selectedJob['result']['system']) && empty($selectedJob['result']['found'])): ?>
    <pre class="log-preview"><?= h(json_encode($selectedJob['result'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) ?></pre>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="panel spaced"><h2>Registered Minecraft servers</h2>
  <?php if (!$all): ?><p class="empty">No Minecraft servers registered yet. Install one below, or import an existing server from discovery or Settings.</p><?php endif; ?>
  <?php foreach($all as $id=>$row):
    $state=manager_running_state((string)$id);
    $pending=($row['install_state']??'')==='pending';
    $status=$pending?'Provisioning':($state['state']??'Not controlled');
    $software=$row['software']??($row['edition']??'java'); ?>
    <div class="server-manager-item">
      <div class="panel-title"><div><h3><?= h($row['name']??$id) ?></h3>
        <p class="muted"><?= h(ucfirst($row['edition']??'java')) ?> · <?= h(ucfirst($software)) ?> · <?= h($row['deployment']??'native') ?> · <?= h($row['version']??'Unknown version') ?></p>
        <code><?= h($row['path']??'') ?></code>
      </div><strong><?= h($status) ?></strong></div>
      <p><a class="subtle" href="?tab=console&amp;server=<?= h($id) ?>">Open console</a></p>
      <?php if (!empty($state['players_online']) || isset($state['players_max'])): ?>
        <p class="muted">Players <?= h($state['players_online']??0) ?> / <?= h($state['players_max']??0) ?><?= !empty($state['server_version'])?' · '.h($state['server_version']):'' ?></p>
      <?php endif; ?>
      <?php if (isset($state['cpu_percent']) || isset($state['memory_mib']) || isset($state['memory'])): ?>
        <p class="muted">CPU <?= h($state['cpu_percent']??'–') ?><?= isset($state['cpu_percent']) && is_numeric($state['cpu_percent'])?'%':'' ?> · RAM <?= h($state['memory']??(isset($state['memory_mib'])?$state['memory_mib'].' MiB':'Unavailable')) ?></p>
      <?php endif; ?>
      <?php if (($row['install_state']??'')==='failed'): ?>
        <p class="hint">Provisioning failed. Review the job error. Unregister this entry in Settings; incomplete files may need manual inspection.</p>
      <?php elseif (!$pending && $workerOnline): ?>
      <form method="post" class="inline-form">
        <?php hidden_csrf(); ?><input type="hidden" name="action" value="manager_action">
        <input type="hidden" name="server_id" value="<?= h($id) ?>">
        <?php foreach(['status'=>'Refresh','adopt'=>'Adopt','start'=>'Start','stop'=>'Stop','restart'=>'Restart','check_updates'=>'Check updates','backup'=>'Backup'] as $operation=>$label): ?>
          <button class="subtle" name="operation" value="<?= h($operation) ?>"><?= h($label) ?></button>
        <?php endforeach; ?>
      </form>
      <form method="post" class="inline-form spaced-small">
        <?php hidden_csrf(); ?><input type="hidden" name="action" value="manager_action">
        <input type="hidden" name="server_id" value="<?= h($id) ?>">
        <label>Target version <input name="version" placeholder="latest or release number" maxlength="48"></label>
        <button class="subtle" name="operation" value="update" data-confirm="Update this server after stopping it and taking a backup?">Update software</button>
      </form>
      <form method="post" class="inline-form spaced-small" data-confirm="Unregister this server? Data deletion, if selected, cannot be undone.">
        <?php hidden_csrf(); ?><input type="hidden" name="action" value="manager_action">
        <input type="hidden" name="server_id" value="<?= h($id) ?>">
        <label>Type exact server name to confirm uninstall <input name="confirm_name" placeholder="<?= h($row['name']??'') ?>" required autocomplete="off"></label>
        <label class="check"><input type="checkbox" name="delete_data" value="1"> Also delete mcmanager-created data (never imported data)</label>
        <button class="subtle danger-text" name="operation" value="uninstall">Uninstall / unregister</button>
      </form>
      <?php elseif (!$workerOnline): ?><p class="hint">Start the local worker to enable lifecycle management.</p>
      <?php else: ?><p class="hint">Installation is queued or in progress. Use the job list to inspect the result.</p><?php endif; ?>
    </div>
  <?php endforeach; ?>
</section>

<section class="panel spaced">
  <h2>Install a new Minecraft server</h2>
  <p class="muted">Each server gets separate persistent storage, ports, limits and lifecycle controls. A working management service and compatible host software are required.</p>
  <form method="post" class="manager-install">
    <?php hidden_csrf(); ?><input type="hidden" name="action" value="manager_install">
    <div class="form-row">
      <label>Server name <input name="name" placeholder="Family server" maxlength="64" required></label>
      <label>Edition <select name="edition"><option value="java">Java Edition</option><option value="bedrock">Bedrock Edition</option></select></label>
    </div>
    <div class="form-row">
      <label>Software <select name="software">
        <option value="paper">Paper (Java)</option><option value="vanilla">Vanilla (Java)</option>
        <option value="spigot">Spigot (container)</option><option value="bukkit">CraftBukkit (container)</option>
        <option value="fabric">Fabric (container)</option><option value="forge">Forge (container)</option>
        <option value="neoforge">NeoForge (container)</option><option value="purpur">Purpur (container)</option>
        <option value="folia">Folia (container)</option><option value="bedrock">Bedrock Dedicated Server</option>
      </select></label>
      <label>Installation <select name="deployment"><option value="native">Native Java server</option><option value="container">Container Manager / Docker</option></select></label>
    </div>
    <div class="form-row">
      <label>Version <input name="version" value="latest" maxlength="48" placeholder="latest or e.g. 26.2"></label>
      <label>Port <input name="port" type="number" min="1024" max="65535" value="25565" required></label>
      <label>Memory limit (MiB) <input name="memory" type="number" min="512" max="32768" value="2048" required></label>
    </div>
    <label class="check"><input name="eula" type="checkbox" value="1" required>
      I accept the Minecraft <a href="https://www.minecraft.net/eula" target="_blank" rel="noopener noreferrer">End User License Agreement</a>.</label>
    <button class="primary" <?= !$workerOnline?'disabled':'' ?>>Create server</button>
  </form>
  <p class="hint">Native managed installation currently supports Java Vanilla and Paper. Containers support the listed Java software or Bedrock, subject to NAS architecture and installed Container Manager. No host Java packages are modified.</p>
</section>

<section class="panel spaced"><div class="panel-title"><h2>Recent management jobs</h2><a class="subtle" href="?tab=manager">Refresh</a></div>
  <?php if (!$recent): ?><p class="empty">No completed jobs yet.</p><?php endif; ?>
  <?php foreach($recent as $job): ?>
    <div class="status-line"><span><?= h($job['operation']??'') ?> — <?= h($job['message']??$job['result']['message']??'') ?></span>
      <a href="?tab=manager&amp;job=<?= h($job['id']??'') ?>"><?= h($job['state']??'') ?> · <?= h(isset($job['finished'])?date('H:i:s',(int)$job['finished']):'') ?></a></div>
  <?php endforeach; ?>
</section>
