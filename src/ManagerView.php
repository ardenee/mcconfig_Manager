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
  <div class="panel-title"><div><h2>Java runtime management</h2>
    <p class="muted">Install a managed Eclipse Temurin JRE without replacing DSM's system Java. Every method verifies the official SHA-256 and the executable's Linux CPU architecture.</p></div>
    <strong><?= h(php_uname('m')) ?></strong>
  </div>
  <div class="manager-install-options">
    <div class="manager-method">
      <h3>1. Known official downloads</h3>
      <p class="hint">Latest official JRE for this NAS. The worker resolves its current upstream download link and SHA-256.</p>
      <form method="post">
        <?php hidden_csrf(); ?>
        <input type="hidden" name="action" value="manager_install_java">
        <input type="hidden" name="source" value="catalog">
        <label>Temurin release
          <select name="major">
            <option value="25">Temurin 25 LTS — latest Minecraft</option>
            <option value="21" selected>Temurin 21 LTS — Minecraft 1.20.5–1.21.x</option>
            <option value="17">Temurin 17 LTS — older Java servers</option>
          </select>
        </label>
        <button class="primary" <?= !$workerOnline?'disabled':'' ?>>Download and install</button>
      </form>
      <p class="hint">Source: <a href="https://adoptium.net/temurin/releases/" target="_blank" rel="noopener noreferrer">Eclipse Adoptium</a>.</p>
    </div>
    <div class="manager-method">
      <h3>2. Download by URL</h3>
      <p class="hint">Paste an official Adoptium Temurin release URL. The archive must match the current verified release for the selected Java version and architecture.</p>
      <form method="post">
        <?php hidden_csrf(); ?>
        <input type="hidden" name="action" value="manager_install_java">
        <input type="hidden" name="source" value="url">
        <label>Java release <select name="major"><option value="21">Java 21</option><option value="25">Java 25</option><option value="17">Java 17</option></select></label>
        <label>Official HTTPS archive URL <input name="url" type="url" required maxlength="1500" placeholder="https://github.com/adoptium/temurin21-binaries/releases/..."></label>
        <button class="subtle" <?= !$workerOnline?'disabled':'' ?>>Download and install</button>
      </form>
    </div>
    <div class="manager-method">
      <h3>3. Upload Java archive</h3>
      <p class="hint">Upload the matching official Temurin Linux JRE <code>.tar.gz</code> archive. The worker rejects an incorrect CPU architecture or checksum.</p>
      <form method="post" enctype="multipart/form-data">
        <?php hidden_csrf(); ?>
        <input type="hidden" name="action" value="manager_install_java">
        <input type="hidden" name="source" value="upload">
        <label>Java release <select name="major"><option value="21">Java 21</option><option value="25">Java 25</option><option value="17">Java 17</option></select></label>
        <label>Temurin archive <input name="java_archive" type="file" accept=".gz,.tgz,.tar.gz" required></label>
        <button class="subtle" <?= !$workerOnline?'disabled':'' ?>>Upload and install</button>
      </form>
    </div>
  </div>
  <p class="hint">Browser upload limit: PHP <?= h(ini_get('upload_max_filesize')) ?> per file / <?= h(ini_get('post_max_size')) ?> per request. Increase both under DSM Web Station → Script Language Settings → the mcconfig PHP profile if your JRE upload is too large. Container servers use Java inside their image.</p>
</section>

<?php if ($selectedJob): ?>
<section class="panel spaced" data-manager-job="<?= h($requestedJob) ?>" data-job-state="<?= h($selectedJob['state']??'queued') ?>">
  <div class="panel-title"><div><h2>Job result</h2><p class="hint">Automatically refreshes every 3 seconds while queued or running.</p></div><a class="subtle" href="?tab=manager&amp;job=<?= h($requestedJob) ?>">Refresh status</a></div>
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
      <div class="status-line"><span>Container Manager</span><strong><?= !empty($s['container_manager_installed'])?'Installed':'Not detected' ?></strong></div>
      <div class="status-line"><span>Docker daemon</span><strong><?= h($s['docker']??'') ?></strong></div>
      <?php if (isset($s['docker_cli'])): ?>
        <div class="status-line"><span>Docker CLI executable</span><code><?= h($s['docker_cli']) ?></code></div>
        <div class="status-line"><span>Docker socket</span><code><?= h($s['docker_socket']??'Unknown') ?></code></div>
        <div class="status-line"><span>Worker account</span><strong><?= h($s['docker_worker_user']??'Unknown') ?></strong></div>
      <?php endif; ?>
      <div class="status-line"><span>Persistent native processes</span><strong><?= !empty($s['persistent_native_supported'])?'Supported':'Unavailable — requires Linux setsid -f' ?></strong></div>
      <div class="status-line"><span>Managed storage</span><code><?= h($s['instances_root']??'') ?></code></div>
      <div class="status-line"><span>Available disk</span><strong><?= h(isset($s['free_storage'])?round($s['free_storage']/1073741824,1).' GiB':'Unavailable') ?></strong></div>
    </div>
    <?php if (!empty($s['docker_error'])): ?><p class="hint">Docker error: <?= h($s['docker_error']) ?></p><?php endif; ?>
    <?php if (empty($s['container_supported'])): ?>
      <p class="hint">On DSM 7, Container Manager is Docker. It can be running while the unprivileged mcconfig worker lacks socket access. Check the socket owner/group and worker identity; never make Docker's socket publicly accessible or world-writable. Docker daemon access effectively grants host-level privileges.</p>
    <?php endif; ?>
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
    $software=$row['software']??($row['edition']??'java');
    $detected=(($row['edition']??'java')==='java' && is_dir((string)($row['path']??'')))?
        jar_detect_version($row):['version'=>'','source'=>''];
    $shownVersion=$detected['version'] ?: (string)($state['version']??($row['version']??''));
    ?>
    <div class="server-manager-item">
      <div class="panel-title"><div><h3><?= h($row['name']??$id) ?></h3>
        <p class="muted"><?= h(ucfirst($row['edition']??'java')) ?> · <?= h(ucfirst($software)) ?> · <?= h($row['deployment']??'native') ?> · Minecraft <?= h($shownVersion?:'version not detected') ?></p>
        <?php if ($detected['source']!==''): ?><p class="hint">Version source: <?= h($detected['source']) ?></p><?php endif; ?>
        <code><?= h($row['path']??'') ?></code>
      </div><strong><?= h($status) ?></strong></div>
      <?php if (($row['deployment']??'native')==='native'): ?>
        <p class="hint">Native recovery preference: <?= manager_native_resume_requested((string)$id)
          ? 'Resume server after worker/NAS restart (when supported)'
          : 'No automatic resume requested' ?></p>
      <?php endif; ?>
      <p><a class="subtle" href="?tab=console&amp;server=<?= h($id) ?>">Open console</a>
      <?php if (plugin_supported($row)): ?> &nbsp; <a class="subtle" href="?tab=plugins&amp;server=<?= h($id) ?>">Manage plugins</a><?php endif; ?></p>
      <?php if (!empty($state['players_online']) || isset($state['players_max'])): ?>
        <p class="muted">Players <?= h($state['players_online']??0) ?> / <?= h($state['players_max']??0) ?><?= !empty($state['server_version'])?' · '.h($state['server_version']):'' ?></p>
      <?php endif; ?>
      <?php if (isset($state['cpu_percent']) || isset($state['memory_mib']) || isset($state['memory'])): ?>
        <p class="muted">CPU <?= h($state['cpu_percent']??'–') ?><?= isset($state['cpu_percent']) && is_numeric($state['cpu_percent'])?'%':'' ?> · RAM <?= h($state['memory']??(isset($state['memory_mib'])?$state['memory_mib'].' MiB':'Unavailable')) ?></p>
      <?php endif; ?>
      <?php if (($row['install_state']??'')==='failed'): ?>
        <p class="hint">Provisioning failed. Review the job error, install a compatible Java runtime if needed, then create a new server. Partial files or containers may require manual inspection.</p>
        <form method="post" class="inline-form" data-confirm="Unregister this failed server? Partial files or containers will be preserved for inspection.">
          <?php hidden_csrf(); ?>
          <input type="hidden" name="action" value="manager_unregister_failed">
          <input type="hidden" name="server_id" value="<?= h($id) ?>">
          <button class="subtle danger-text">Unregister failed entry (keep files)</button>
        </form>
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
      <details class="manager-backups">
        <summary>Backups and restoration (<?= count(manager_backup_files((string)$id)) ?> snapshots)</summary>
        <p class="hint">Backups require a stopped server. Restore verifies SHA-256, creates a safety snapshot and swaps server folders. Synology ACLs are not preserved; externally owned/imported folders may require manual permission repair. Older TAR archives are retained but not restored directly.</p>
        <form method="post" class="inline-form spaced-small">
          <?php hidden_csrf(); ?><input type="hidden" name="action" value="manager_backup_retention">
          <input type="hidden" name="server_id" value="<?= h($id) ?>">
          <label>Retain most recent backup snapshots
            <select name="backup_keep">
              <?php foreach([3,5,10,15,20,30] as $keep): ?>
                <option value="<?= $keep ?>" <?= (int)($row['backup_keep']??10)===$keep?'selected':'' ?>><?= $keep ?></option>
              <?php endforeach; ?>
            </select></label>
          <button class="subtle" type="submit">Save retention</button>
        </form>
        <?php foreach(manager_backup_files((string)$id) as $backup): ?>
          <div class="manager-backup-entry">
            <div>
              <strong><?= h($backup['name']) ?></strong>
              <small><?= h(date('Y-m-d H:i',$backup['created'])) ?> · <?= h(round($backup['size']/1048576,1)) ?> MiB</small>
            </div>
            <form method="post" class="inline-form">
              <?php hidden_csrf(); ?><input type="hidden" name="action" value="manager_action">
              <input type="hidden" name="server_id" value="<?= h($id) ?>">
              <input type="hidden" name="backup_name" value="<?= h($backup['name']) ?>">
              <button class="subtle" name="operation" value="verify_backup" <?= !$workerOnline?'disabled':'' ?>>Verify SHA-256</button>
            </form>
            <form method="post" class="inline-form" data-confirm="Restore this backup? You must stop the server first. The existing server will be backed up before restoration.">
              <?php hidden_csrf(); ?><input type="hidden" name="action" value="manager_action">
              <input type="hidden" name="server_id" value="<?= h($id) ?>">
              <input type="hidden" name="backup_name" value="<?= h($backup['name']) ?>">
              <label>Confirm exact server name <input type="text" name="confirm_name" required autocomplete="off" placeholder="<?= h($row['name']??'') ?>"></label>
              <button class="subtle" name="operation" value="restore_backup" <?= !$workerOnline?'disabled':'' ?>>Restore backup</button>
            </form>
          </div>
        <?php endforeach; ?>
        <?php if (!manager_backup_files((string)$id)): ?>
          <p class="muted">No verified ZIP snapshots yet. Stop this server and use Backup to create one.</p>
        <?php endif; ?>
      </details>
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

<section class="panel spaced">
  <div class="panel-title"><h2>Queued management jobs</h2><a class="subtle" href="?tab=manager">Refresh</a></div>
  <?php $queued=manager_queued_jobs(); ?>
  <?php if (!$queued): ?><p class="muted">No pending jobs.</p><?php endif; ?>
  <?php foreach($queued as $job): ?>
    <div class="status-line">
      <span><?= h($job['operation']??'Unknown') ?> — <?= h($all[$job['server_id']??'']['name']??'Host operation') ?></span>
      <form method="post" class="inline-form" data-confirm="Cancel this queued operation before it starts?">
        <?php hidden_csrf(); ?><input type="hidden" name="action" value="manager_cancel_job">
        <input type="hidden" name="job_id" value="<?= h($job['id']) ?>">
        <button class="subtle">Cancel pending</button>
      </form>
    </div>
  <?php endforeach; ?>
  <p class="hint">Running tasks cannot be force-cancelled: terminating a backup, restore, update or server installation mid-operation can damage files.</p>
</section>

<section class="panel spaced"><div class="panel-title"><h2>Recent management jobs</h2><a class="subtle" href="?tab=manager">Refresh</a></div>
  <?php if (!$recent): ?><p class="empty">No completed jobs yet.</p><?php endif; ?>
  <?php foreach($recent as $job): ?>
    <div class="status-line"><span><?= h($job['operation']??'') ?> — <?= h($job['message']??$job['result']['message']??'') ?></span>
      <a href="?tab=manager&amp;job=<?= h($job['id']??'') ?>"><?= h($job['state']??'') ?> · <?= h(isset($job['finished'])?date('H:i:s',(int)$job['finished']):'') ?></a></div>
  <?php endforeach; ?>
</section>
