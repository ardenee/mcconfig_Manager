<?php
$workerInfo=manager_heartbeat();
$workerOnline=!empty($workerInfo['online']);
$dockerAccess=manager_docker_capability();
$dockerAvailable=!empty($dockerAccess['available']);
$recent=manager_recent_jobs();
$requestedJob=$_GET['job']??'';
$selectedJob=is_string($requestedJob) && preg_match('/^[a-f0-9]{24}$/D',$requestedJob)
    ? manager_result($requestedJob):null;
?>
<section class="panel mc-worker-panel">
  <div class="mc-worker-row">
    <div><h2>Server Manager</h2><p class="hint">Manage your Minecraft servers from one place.</p></div>
    <div class="mc-worker-summary">
      <span data-worker-status class="mc-status mc-status--<?= $workerOnline?'running':'warning' ?>" role="status">Management service <?= $workerOnline?'online':'offline' ?></span>
      <?php if ($selectedJob): ?><a class="subtle" href="#mc-selected-job">View selected job: <?= h($selectedJob['state']??'queued') ?></a><?php endif; ?>
    </div>
  </div>
  <?php if (!$workerOnline): ?>
    <div class="alert warning">The management service is offline; status information and server controls may not be available. Start mcconfig in DSM Package Center.</div>
  <?php elseif (!$dockerAvailable): ?>
    <p class="hint">The worker has no permanent Docker access. Use <strong>Install a Bedrock Edition server — one-time DSM authorization</strong> or the existing-container approval panel below. Image download, creation and start each require fresh DSM administrator authorization; nothing is stored.</p>
  <?php endif; ?>
  <details class="mc-worker-tools">
    <summary>Diagnostics and server discovery</summary>
    <p class="hint">PHP <?= h($workerInfo['php']??'not available') ?> · Last check <?= h(!empty($workerInfo['timestamp'])?date('H:i:s',(int)$workerInfo['timestamp']):'none') ?></p>
    <form method="post" class="inline-form"><?php hidden_csrf(); ?>
      <button name="action" value="manager_diagnostics" class="subtle" <?= !$workerOnline?'disabled':'' ?>>Check hardware and Java</button>
      <button name="action" value="manager_discover" class="subtle" <?= !$workerOnline?'disabled':'' ?>>Find existing servers</button>
    </form>
  </details>
</section>


<details id="mc-dsm-on-demand" class="mc-feature-group" <?= is_array($dsmReport)?'open':'' ?>>
  <summary>Experimental: authorize one DSM Container Manager action (no saved credentials)</summary>
  <p class="hint">Use a DSM administrator account for <strong>one operation only</strong>. Credentials and the temporary DSM session are used in this HTTPS request, then discarded; the adapter attempts DSM logout even when an operation fails. The mcconfig service account keeps its normal restricted permissions.</p>
  <p class="hint">Check Container Manager visibility, verify a registered container, or authorize Start/Stop. For new Bedrock servers, use the staged <strong>Install a Bedrock Edition server</strong> section below. Each operation requires a fresh approval; no Docker socket permissions are changed.</p>
  <?php if (PHP_OS_FAMILY!=='Linux'): ?>
    <div class="alert warning">DSM authorization is available only when running the web interface on the Synology NAS.</div>
  <?php elseif (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off'): ?>
    <div class="alert warning">Open mcmanager using <strong>HTTPS</strong> to enter DSM administrator credentials. No password form is displayed over HTTP.</div>
  <?php elseif (!extension_loaded('curl')): ?>
    <div class="alert warning">Enable the PHP <code>curl</code> extension in the mcconfig Web Station profile. The SPK requests it from version 0.5.8 onward.</div>
  <?php else: ?>
    <form action="?tab=manager#mc-dsm-on-demand" method="post" autocomplete="off" class="mc-dsm-approval-form">
      <?php hidden_csrf(); ?>
      <input type="hidden" name="action" value="dsm_approve_once">
      <div class="form-row">
        <label>Action to authorize
          <select name="dsm_operation" required>
            <option value="test">Test DSM authorization &amp; list containers (read-only)</option>
            <?php if (array_filter($all,fn($s)=>is_array($s)&&($s['deployment']??'native')==='container'&&!empty($s['container_name']))): ?>
              <option value="start">Start one registered Minecraft container</option>
              <option value="stop">Stop one registered Minecraft container</option>
              <option value="bedrock-verify">Verify an installed Bedrock container</option>
            <?php endif; ?>
          </select>
        </label>
        <label>Target (used only for Start/Stop)
          <select name="server_id">
            <option value="">No target — authorization test</option>
            <?php foreach($all as $targetId=>$targetRow): ?>
              <?php if (is_array($targetRow)&&($targetRow['deployment']??'native')==='container'&&!empty($targetRow['container_name'])): ?>
                <option value="<?= h($targetId) ?>"><?= h($targetRow['name']??$targetId) ?> — <?= h($targetRow['container_name']) ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <div class="form-row">
        <label>DSM administrator username <input name="dsm_account" autocomplete="off" maxlength="64" required></label>
        <label>DSM administrator password <input type="password" name="dsm_password" autocomplete="new-password" maxlength="1024" required></label>
        <label>DSM one-time code (if enabled) <input name="dsm_otp" autocomplete="off" inputmode="numeric" maxlength="8" placeholder="Optional"></label>
      </div>
      <button class="primary" type="submit">Authorize selected DSM action</button>
      <p class="hint">Use <strong>HTTPS</strong> to access mcmanager before entering DSM credentials. The server contacts DSM at <code>127.0.0.1:5000</code> locally, without redirects, proxies, or externally supplied destinations. Authentication is limited to four attempts per 15 minutes. No background or unattended Docker access is granted.</p>
    </form>
  <?php endif; ?>
  <?php if (is_array($dsmReport)): ?>
    <div class="alert success"><?= h($dsmReport['message']??'DSM operation succeeded') ?></div>
    <?php if (($dsmReport['operation']??'')==='test'): ?>
      <details open><summary>DSM Container Manager reported <?= (int)($dsmReport['count']??0) ?> container(s)</summary>
        <?php foreach($dsmReport['containers']??[] as $dsmItem): ?>
          <div class="status-line"><span><?= h($dsmItem['name']??'') ?> <small><?= h($dsmItem['image']??'') ?></small></span><strong><?= h($dsmItem['status']??'') ?></strong></div>
        <?php endforeach; ?>
      </details>
    <?php endif; ?>
  <?php endif; ?>
</details>

<?php $hasBedrock=false;foreach($all as $existingBedrock) {
    if (is_array($existingBedrock)&&($existingBedrock['edition']??'')==='bedrock') {$hasBedrock=true;break;}
} ?>
<details id="mc-create-bedrock" class="mc-feature-group mc-bedrock-install" <?= !$hasBedrock?'open':'' ?>>
  <summary>Install a Bedrock Edition server — one-time DSM authorization</summary>
  <p class="hint">Bedrock uses a Container Manager image. This installer needs <strong>no permanent Docker permission</strong> for mcmanager. Each operation requires a new DSM administrator login: (1) check/download the image; (2) create the stopped container; (3) separately authorize Start.</p>
  <?php if (PHP_OS_FAMILY!=='Linux'): ?>
    <div class="alert warning">Install the DSM package on your Synology NAS to use this Bedrock installer.</div>
  <?php elseif (empty($_SERVER['HTTPS'])||$_SERVER['HTTPS']==='off'): ?>
    <div class="alert warning">Open mcmanager over HTTPS before using temporary administrator authorization.</div>
  <?php elseif (!extension_loaded('curl')): ?>
    <div class="alert warning">Enable the PHP curl extension in mcconfig's Web Station PHP profile.</div>
  <?php elseif (!in_array(strtolower(php_uname('m')),['x86_64','amd64'],true)): ?>
    <div class="alert warning">The Linux Bedrock Dedicated Server Docker image requires x86-64 hardware. This NAS reports <?= h(php_uname('m')) ?>.</div>
  <?php else: ?>
    <div class="mc-bedrock-steps">
      <div class="mc-bedrock-step">
        <h3>1. Check or download the Bedrock image</h3>
        <p class="hint">If the image is missing, authorize a download. It continues within Container Manager after mcmanager logs out. Check its download progress in Container Manager → Image.</p>
        <form method="post" action="?tab=manager#mc-create-bedrock" autocomplete="off">
          <?php hidden_csrf(); ?>
          <input type="hidden" name="action" value="dsm_approve_once">
          <label>Image operation <select name="dsm_operation">
              <option value="bedrock-image">Check if Bedrock image is ready (read-only)</option>
              <option value="bedrock-pull">Download official itzg Bedrock Docker image</option>
          </select></label>
          <div class="form-row">
            <label>DSM administrator username <input name="dsm_account" required autocomplete="off" maxlength="64"></label>
            <label>Password (this request only) <input name="dsm_password" type="password" required autocomplete="new-password" maxlength="1024"></label>
            <label>OTP, if enabled <input name="dsm_otp" autocomplete="off" maxlength="8" inputmode="numeric"></label>
          </div>
          <button class="subtle" type="submit">Authorize image operation</button>
        </form>
      </div>
      <div class="mc-bedrock-step">
        <h3>2. Create your Bedrock server</h3>
        <p class="hint">Requires the downloaded image. Stores worlds under mcmanager's managed Minecraft instances directory. Creates the container in the stopped state; does not affect existing containers or port assignments.</p>
        <form method="post" action="?tab=manager#mc-create-bedrock" autocomplete="off"
          data-confirm="Create a new Bedrock container on this NAS using the selected UDP port and managed storage? The image must already be downloaded.">
          <?php hidden_csrf(); ?>
          <input type="hidden" name="action" value="dsm_approve_once">
          <input type="hidden" name="dsm_operation" value="bedrock-create">
          <div class="form-row">
            <label>Server name <input name="bedrock_name" required maxlength="64" placeholder="Bedrock server"></label>
            <label>UDP server port <input name="bedrock_port" type="number" min="1024" max="65535" value="19132" required></label>
            <label>Memory limit (MiB) <input name="bedrock_memory" type="number" min="512" max="32768" value="2048" required></label>
          </div>
          <label class="check"><input type="checkbox" name="bedrock_eula" value="1" required>
            I accept the <a href="https://www.minecraft.net/eula" target="_blank" rel="noopener noreferrer">Minecraft EULA</a>.</label>
          <div class="form-row">
            <label>DSM administrator username <input name="dsm_account" required autocomplete="off" maxlength="64"></label>
            <label>Password (this request only) <input name="dsm_password" type="password" required autocomplete="new-password" maxlength="1024"></label>
            <label>OTP, if enabled <input name="dsm_otp" autocomplete="off" maxlength="8" inputmode="numeric"></label>
          </div>
          <button type="submit" class="primary">Authorize and create Bedrock server</button>
        </form>
      </div>
    </div>
  <?php endif; ?>
  <p class="hint">This is experimental DSM 7 Container Manager integration. Container creation uses Synology's undocumented API and must be verified on this NAS. No automatic administrator session or password is retained.</p>
</details>

<section class="panel spaced" data-manager-servers>
  <h2>Your Minecraft servers</h2>
  <p class="hint">Green = running, red = stopped, yellow = needs attention or a status check. Select a server below; maintenance options are collapsed until needed.</p>
  <?php if (!$all): ?><p class="empty">No Minecraft servers registered yet. Install one below, or import an existing server from discovery or Settings.</p><?php endif; ?>
  <?php foreach($all as $id=>$row):
    $state=manager_running_state((string)$id);
    $pending=($row['install_state']??'')==='pending';
    $health=manager_server_status($row,$state,$workerOnline);
    $dsmOnly=($row['control_mode']??'')==='dsm-on-demand';
    if ($dsmOnly) $health=['label'=>($row['install_state']??'ready')==='unverified'?'Needs DSM verification':'Check in DSM','tone'=>'warning','running'=>false,'stopped'=>false,'hint'=>'Authorize DSM Container Manager action to check this container'];
    $status=$health['label'];
    $software=$row['software']??($row['edition']??'java');
    $detected=(($row['edition']??'java')==='java' && is_dir((string)($row['path']??'')))?
        jar_detect_version($row):['version'=>'','source'=>''];
    $shownVersion=$detected['version'] ?: (string)($state['version']??($row['version']??''));
    ?>
    <div class="server-manager-item mc-server-card" data-server-card="<?= h($id) ?>">
      <div class="panel-title mc-server-heading"><div><h3><?= h($row['name']??$id) ?></h3>
        <p class="muted"><?= h(ucfirst($row['edition']??'java')) ?> · <?= h(ucfirst($software)) ?> · <?= h($row['deployment']??'native') ?> · Minecraft <?= h($shownVersion?:'version not detected') ?></p>

      </div><span data-server-status class="mc-status mc-status--<?= h($health['tone']) ?>" role="status" title="<?= h($health['hint']) ?>"><?= h($status) ?></span></div>
      <div class="mc-primary-actions" data-server-actions>
        <?php if ($dsmOnly): ?>
          <a class="subtle" href="#mc-dsm-on-demand">Authorize DSM action</a>
        <?php elseif ($workerOnline && !$pending && ($row['install_state']??'ready')!=='failed'): ?>
          <?php if ($health['stopped']): ?>
            <form method="post" class="inline-form">
              <?php hidden_csrf(); ?>
              <input type="hidden" name="action" value="manager_action">
              <input type="hidden" name="server_id" value="<?= h($id) ?>">
              <button class="mc-action-start" name="operation" value="start">Start server</button>
            </form>
          <?php elseif ($health['running']): ?>
            <form method="post" class="inline-form">
              <?php hidden_csrf(); ?>
              <input type="hidden" name="action" value="manager_action">
              <input type="hidden" name="server_id" value="<?= h($id) ?>">
              <button class="subtle" name="operation" value="stop">Stop</button>
              <button class="subtle" name="operation" value="restart" data-confirm="Restart this Minecraft server now?">Restart</button>
            </form>
          <?php else: ?>
            <form method="post" class="inline-form">
              <?php hidden_csrf(); ?>
              <input type="hidden" name="action" value="manager_action">
              <input type="hidden" name="server_id" value="<?= h($id) ?>">
              <button class="subtle" name="operation" value="status">Check status</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
        <?php if (!$dsmOnly): ?><a class="subtle" href="?tab=console&amp;server=<?= h($id) ?>">Console</a><?php endif; ?>
        <a class="subtle" href="?tab=properties&amp;server=<?= h($id) ?>">Properties</a>
        <?php if (($row['edition']??'java')==='java'): ?><a class="subtle" href="?tab=plugins&amp;server=<?= h($id) ?>">Plugins</a><?php endif; ?>
      </div>
      <details class="mc-server-detail">
        <summary>Details, updates and maintenance</summary>
        <p class="hint">Server storage: <code><?= h($row['path']??'') ?></code></p>
        <?php if ($detected['source']!==''): ?><p class="hint">Minecraft version source: <?= h($detected['source']) ?></p><?php endif; ?>
        <?php if (($row['deployment']??'native')==='native'): ?>
          <p class="hint">Automatic restart recovery: <?= manager_native_resume_requested((string)$id)?'Enabled':'Not requested' ?></p>
        <?php endif; ?>
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
      <?php elseif (!$pending && $workerOnline && !$dsmOnly): ?>
      <form method="post" class="inline-form mc-secondary-actions">
        <?php hidden_csrf(); ?><input type="hidden" name="action" value="manager_action">
        <input type="hidden" name="server_id" value="<?= h($id) ?>">
        <?php foreach(['status'=>'Refresh','adopt'=>'Adopt','check_updates'=>'Check updates','backup'=>'Create backup'] as $operation=>$label): ?>
          <button class="subtle" name="operation" value="<?= h($operation) ?>"><?= h($label) ?></button>
        <?php endforeach; ?>
      </form>
      <form method="post" class="inline-form spaced-small">
        <?php hidden_csrf(); ?><input type="hidden" name="action" value="manager_action">
        <input type="hidden" name="server_id" value="<?= h($id) ?>">
        <label>Target version <input name="version" placeholder="latest or release number" maxlength="48"></label>
        <button class="subtle" name="operation" value="update" data-confirm="Update this server after stopping it and taking a backup?">Update software</button>
      </form>
      <?php if (($row['edition']??'java')==='java' && ($row['deployment']??'native')==='native'): ?>
      <details class="manager-backups manager-startup">
        <summary>Startup settings - Java heap and JVM options</summary>
        <p class="hint">These settings apply on the next server start. A running Minecraft process is not interrupted. Each extra JVM option is passed directly as an argument (not through a shell).</p>
        <form method="post" class="manager-startup-form">
          <?php hidden_csrf(); ?>
          <input type="hidden" name="action" value="manager_startup">
          <input type="hidden" name="server_id" value="<?= h($id) ?>">
          <div class="form-row">
            <label>Initial heap (Xms), MiB
              <input name="xms" type="number" min="128" max="32768" required value="<?= h($row['xms']??256) ?>">
            </label>
            <label>Maximum heap (Xmx), MiB
              <input name="memory" type="number" min="512" max="32768" required value="<?= h($row['memory']??2048) ?>">
            </label>
          </div>
          <label>Advanced JVM arguments (one supported flag per line)
            <textarea name="jvm_args" rows="3" maxlength="1800" placeholder="-XX:+UseG1GC&#10;-XX:MaxGCPauseMillis=200"><?= h($row['jvm_args']??'') ?></textarea>
          </label>
          <input type="hidden" name="native_access" value="0">
          <label class="check"><input type="checkbox" name="native_access" value="1" <?= !empty($row['native_access'])?'checked':'' ?>>
            Enable native access for trusted Java libraries (Java 25 JNA warning)
          </label>
          <input type="hidden" name="netty_workdir" value="0">
          <label class="check"><input type="checkbox" name="netty_workdir" value="1" <?= !array_key_exists('netty_workdir',$row)||!empty($row['netty_workdir'])?'checked':'' ?>>
            Use a private Netty native-library directory within this server (recommended on DSM when /tmp is noexec)
          </label>
          <button class="subtle" type="submit">Save startup settings</button>
        </form>
        <p class="hint">Supported extra flags include G1GC, MaxGCPauseMillis, processor/thread counts, AlwaysPreTouch, encoding and timezone. Heap is configured above; JVM agents, classpath changes and shell commands are not accepted. Native access suppresses the JNA restricted-method warning but does not remove the separate JOML sun.misc.Unsafe deprecation warning. For the "Server empty ... pausing" message, change <code>pause-when-empty-seconds</code> in Server Properties instead.</p>
      </details>
      <?php endif; ?>
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
      </details>
    </div>
  <?php endforeach; ?>
</section>


<?php if ($selectedJob): ?>
<section id="mc-selected-job" class="panel spaced" data-manager-job="<?= h($requestedJob) ?>" data-job-state="<?= h($selectedJob['state']??'queued') ?>">
  <div class="panel-title"><div><h2>Job result</h2><p class="hint">Automatically checks every 3 seconds, including after completion. <span data-manager-sync aria-live="polite">Connecting…</span></p></div><a class="subtle" href="?tab=manager&amp;job=<?= h($requestedJob) ?>">Refresh status</a></div>
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



<details id="mc-create-server" class="mc-feature-group" <?php if (!$all): ?>open<?php endif; ?>>
<summary>Create a Minecraft server</summary>
<section class="panel spaced">
  <h2>Install a new Minecraft server</h2>
  <p class="muted">Each server gets separate persistent storage, ports, limits and lifecycle controls. A working management service and compatible host software are required.</p>
  <?php if (!$dockerAvailable):
    $dockerGuide=manager_docker_permission_advice($dockerAccess);
    $dockerUser=(string)($dockerAccess['user']??'mcconfig');
    $dockerBin=(string)($dockerAccess['binary']??'docker');
    if (!preg_match('~^/?[A-Za-z0-9_./-]+$~D',$dockerBin)) $dockerBin='docker';
    if (!preg_match('/^[A-Za-z0-9_@.-]+$/D',$dockerUser)) $dockerUser='mcconfig';
  ?>
    <div class="mc-docker-warning alert warning">
      <strong>Worker-based container installation unavailable: no Docker socket permission</strong>
      <p><?= h($dockerAccess['message']) ?></p>
      <p><strong>Optional legacy worker method:</strong> The worker would require read/write permission on <code>/var/run/docker.sock</code>. For one-off existing-container actions, use the temporary DSM administrator authorization section above instead. It does not alter package group memberships.</p>
      <p><strong>What to check:</strong> <?= h($dockerGuide['message']) ?></p>
      <details class="mc-docker-guide">
        <summary>Advanced alternative: grant ongoing Docker socket access to the worker</summary>
        <div class="mc-docker-facts">
          <div><span>Service account</span><code><?= h($dockerUser) ?></code></div>
          <div><span>Socket group</span><code><?= h((string)($dockerAccess['socket_group']??'Not detected')) ?></code></div>
          <div><span>Socket permissions</span><code><?= h((string)($dockerAccess['socket_mode']??'Not detected')) ?></code></div>
          <div><span>Member of socket group</span><strong><?= isset($dockerAccess['socket_gid'])?(!empty($dockerAccess['worker_in_socket_group'])?'Yes':'No'):'Not detected' ?></strong></div>
        </div>
        <ol>
          <li>Connect to the NAS using SSH with a DSM administrator account. Inspect the <em>actual</em> socket ownership and group membership:
            <pre class="mc-docker-commands">sudo ls -l /var/run/docker.sock
id <?= h($dockerUser) ?></pre>
          </li>
          <li>After reviewing the security implications, arrange for this <em>package service account</em> to belong to the socket's approved access group, with group read/write access. If the socket group is <code>root</code>, <strong>do not add the service account to the root group</strong>. Use an administrator-configured, dedicated restricted Docker socket group instead. Follow the NAS's supported group and service configuration process; do not guess the group name.</li>
          <li>Restart the mcconfig package so its worker inherits the updated group membership. Confirm access as the service account:
            <pre class="mc-docker-commands">sudo -u <?= h($dockerUser) ?> <?= h($dockerBin) ?> version</pre>
            Then reload Server Manager. Container options will appear automatically after a successful permission check.
          </li>
        </ol>
        <details class="mc-docker-gui-setup">
          <summary>Alternative: use the DSM GUI to authorize the change (no password in mcmanager)</summary>
          <p>In DSM, open <strong>Control Panel → Task Scheduler → Create → Scheduled Task → User-defined script</strong>. Under General, choose <strong>root</strong> as the task user. On Task Settings, paste the administrator-reviewed commands below and run the task manually once. Disable or delete the task afterwards. The <code>mcconfig</code> service user is a package-internal account, so DSM's regular User &amp; Group screen may not expose the membership change.</p>
          <p class="hint">The following example is for a <strong>new dedicated Docker-socket group</strong>. It never asks for or stores the root password. Review the existing socket ownership and any other software relying on it before changing the group.</p>
          <pre class="mc-docker-commands">set -eu
test "$(id -u)" -eq 0
test -S /var/run/docker.sock
id <?= h($dockerUser) ?>
if ! getent group mcmanager-docker >/dev/null 2>&amp;1; then
  /usr/syno/sbin/synogroup --add mcmanager-docker <?= h($dockerUser) ?>
fi
id -nG <?= h($dockerUser) ?> | tr ' ' '\n' | grep -qx mcmanager-docker || {
  echo "Group exists without the package user; stop and review membership" >&amp;2
  exit 1
}
chgrp mcmanager-docker /var/run/docker.sock
chmod 0660 /var/run/docker.sock
ls -l /var/run/docker.sock</pre>
          <p class="hint">If a group already exists without the service user, stop and review it instead of replacing its membership. After the task completes, restart mcconfig in Package Center and refresh its Docker status. This grants persistent account membership; the socket group ownership can reset after Container Manager restarts. If you need access restricted to each individual Docker action instead, that requires a separate, tightly controlled privileged broker—not a root-password field in the website.</p>
        </details>
        <p class="hint"><strong>Security:</strong> Docker socket access grants root-equivalent control over the NAS. mcmanager will never change socket ownership or permissions automatically. Never use <code>chmod 666</code> or an unauthenticated network Docker endpoint. Container Manager/DSM updates can reset socket access; recheck after upgrades. Never enter NAS administrator or root credentials into the mcmanager web form.</p>
        <p class="hint"><a href="https://docs.docker.com/engine/install/linux-postinstall/" target="_blank" rel="noopener noreferrer">Docker documentation: socket group privileges</a></p>
      </details>
      <p class="hint">Native Java Vanilla/Paper remains available. Bedrock can now use the separate temporary DSM authorization installer above; this ongoing worker-access guide is optional, not required for the staged Bedrock workflow.</p>
    </div>
  <?php endif; ?>
  <form method="post" class="manager-install">
    <?php hidden_csrf(); ?><input type="hidden" name="action" value="manager_install">
    <div class="form-row">
      <label>Server name <input name="name" placeholder="Family server" maxlength="64" required></label>
      <label>Edition <select name="edition"><option value="java">Java Edition</option><?php if ($dockerAvailable): ?><option value="bedrock">Bedrock Edition</option><?php endif; ?></select></label>
    </div>
    <div class="form-row">
      <label>Software <select name="software">
        <option value="paper">Paper (Java)</option><option value="vanilla">Vanilla (Java)</option>
        <?php if ($dockerAvailable): ?>
        <option value="spigot">Spigot (container)</option><option value="bukkit">CraftBukkit (container)</option>
        <option value="fabric">Fabric (container)</option><option value="forge">Forge (container)</option>
        <option value="neoforge">NeoForge (container)</option><option value="purpur">Purpur (container)</option>
        <option value="folia">Folia (container)</option><option value="bedrock">Bedrock Dedicated Server</option>
        <?php endif; ?>
      </select></label>
      <label>Installation <select name="deployment"><option value="native">Native Java server</option><?php if ($dockerAvailable): ?><option value="container">Container Manager / Docker</option><?php endif; ?></select></label>
    </div>
    <div class="form-row">
      <label>Version <input name="version" value="latest" maxlength="48" placeholder="latest or e.g. 26.2"></label>
      <label>Port <input name="port" type="number" min="1024" max="65535" value="25565" required></label>
      <label>Maximum heap / memory (MiB) <input name="memory" type="number" min="512" max="32768" value="2048" required></label>
    </div>
    <details class="manager-install-launch">
      <summary>Optional native Java startup arguments</summary>
      <p class="hint">For native Java servers only. Container memory is a container limit set at creation.</p>
      <label>Initial Java heap (Xms), MiB <input name="xms" type="number" min="128" max="32768" value="256"></label>
      <label>Advanced JVM flags (one supported flag per line)
        <textarea name="jvm_args" rows="3" maxlength="1800" placeholder="-XX:+UseG1GC&#10;-XX:MaxGCPauseMillis=200"></textarea>
      </label>
      <input type="hidden" name="native_access" value="0">
      <label class="check"><input type="checkbox" name="native_access" value="1"> Enable native access for trusted Java libraries (Java 25)
      </label>
      <input type="hidden" name="netty_workdir" value="0">
      <label class="check"><input type="checkbox" name="netty_workdir" value="1" checked> Use server-local Netty native-library directory (recommended for DSM noexec /tmp)</label>
    </details>
    <label class="check"><input name="eula" type="checkbox" value="1" required>
      I accept the Minecraft <a href="https://www.minecraft.net/eula" target="_blank" rel="noopener noreferrer">End User License Agreement</a>.</label>
    <button class="primary" <?= !$workerOnline?'disabled':'' ?>>Create server</button>
  </form>
  <p class="hint">Native managed installation supports Java Vanilla and Paper. <?php if ($dockerAvailable): ?>Containers can also be installed and adopted with validated Docker access.<?php else: ?>Container/Bedrock deployment is hidden until the worker has verified Docker access.<?php endif; ?> No host Java packages are modified.</p>
</section>

</details>

<details class="mc-feature-group">
<summary>Java runtimes and installation</summary>
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

</details>

<details class="mc-feature-group" <?php if (manager_queued_jobs()): ?>open<?php endif; ?>>
<summary>Pending management tasks</summary>
<section class="panel spaced" data-manager-queued>
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

</details>

<details class="mc-feature-group">
<summary>Recent jobs and history</summary>
<section class="panel spaced" data-manager-recent><div class="panel-title"><h2>Recent management jobs (history)</h2><a class="subtle" href="?tab=manager">Refresh</a></div>
  <p class="hint">These are past operations, not current server health. Earlier failures remain in the history after a later successful installation. This list updates automatically every 3 seconds. <span data-manager-sync aria-live="polite">Connecting…</span></p>
  <?php if (!$recent): ?><p class="empty">No completed jobs yet.</p><?php endif; ?>
  <?php foreach($recent as $job): ?>
    <div class="status-line"><span><?= h($job['operation']??'') ?> — <?= h($job['message']??$job['result']['message']??'') ?></span>
      <a href="?tab=manager&amp;job=<?= h($job['id']??'') ?>"><?= h($job['state']??'') ?> · <?= h(isset($job['finished'])?date('H:i:s',(int)$job['finished']):'') ?></a></div>
  <?php endforeach; ?>
</section>
</details>
