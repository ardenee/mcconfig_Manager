<?php
$workerInfo=manager_heartbeat();
$workerOnline=!empty($workerInfo['online']);
$platform=mc_platform();
$dockerAccess=$platform==='dsm'?manager_docker_capability():['available'=>false];
$dockerAvailable=$platform==='dsm'&&!empty($dockerAccess['available']);
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
    <div class="alert warning">The management service is offline; status information and server controls may not be available. Start the local mcmanager worker to enable installation and server control.</div>
  <?php elseif ($platform==='windows'): ?>
    <p class="hint"><?= !empty($workerInfo['service_mode'])?
      'Windows Service active: '.h($workerInfo['service_name']??'mcmanager-worker'):
      'Local development worker is running; install the Windows Service for automatic startup and crash recovery.' ?></p>
  <?php elseif ($platform==='dsm' && !$dockerAvailable): ?>
    <p class="hint">The worker has no permanent Docker access. Use <strong>Install a Bedrock Edition server — one-time DSM authorization</strong> or the existing-container approval panel below. Image download, creation and start each require fresh DSM administrator authorization; nothing is stored.</p>
  <?php endif; ?>
  <details class="mc-worker-tools">
    <summary>Diagnostics and server discovery</summary>
    <p class="hint">PHP <?= h($workerInfo['php']??'not available') ?> · Last check <?= h(!empty($workerInfo['timestamp'])?date('H:i:s',(int)$workerInfo['timestamp']):'none') ?></p>
    <form method="post" class="inline-form"><?php hidden_csrf(); ?>
      <button name="action" value="manager_diagnostics" class="subtle" <?= !$workerOnline?'disabled':'' ?>>Check hardware and Java</button>
    </form>
    <details class="mc-feature-group">
      <summary>Third-party local server discovery (optional)</summary>
      <p class="hint">Search only known local Minecraft storage directories. Imported servers remain owned by their original installers; this is separate from creating a managed server.</p>
      <form method="post" class="inline-form"><?php hidden_csrf(); ?>
        <button name="action" value="manager_discover" class="subtle" <?= !$workerOnline?'disabled':'' ?>>Find existing local servers</button>
      </form>
    </details>
  </details>
</section>


<?php if ($platform==='dsm'): ?>
<?php require __DIR__.'/BedrockWizardView.php'; ?>

<details id="mc-dsm-on-demand" class="mc-feature-group" <?= is_array($dsmReport)||!empty($_GET['dsm_server'])?'open':'' ?>>
  <summary>Advanced: manual Container Manager actions</summary>
  <p class="hint">Use a DSM administrator account for <strong>one operation only</strong>. Credentials and the temporary DSM session are used in this HTTPS request, then discarded; the adapter attempts DSM logout even when an operation fails. The mcconfig service account keeps its normal restricted permissions.</p>
  <p class="hint">Check Container Manager visibility, verify a registered container, or authorize Start/Stop. For new Bedrock servers, use the automatic installation wizard above. Each operation requires a fresh approval; no Docker socket permissions are changed.</p>
  <?php if (PHP_OS_FAMILY!=='Linux'): ?>
    <div class="alert warning">DSM authorization is available only when running the web interface on the Synology NAS.</div>
  <?php elseif (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off'): ?>
    <div class="alert warning">Open mcmanager using <strong>HTTPS</strong> to enter DSM administrator credentials. No password form is displayed over HTTP.</div>
  <?php elseif (!extension_loaded('curl')): ?>
    <div class="alert warning">Enable the PHP <code>curl</code> extension in the mcconfig Web Station profile. The SPK requests it from version 0.5.8 onward.</div>
  <?php else: ?>
    <form action="?tab=manager#mc-dsm-on-demand" method="post" autocomplete="off" class="mc-dsm-approval-form" data-dsm-approval>
      <?php hidden_csrf(); ?>
      <input type="hidden" name="action" value="dsm_approve_once">
      <div class="form-row">
        <label>Action to authorize
          <select name="dsm_operation" required>
            <option value="test">Test DSM authorization &amp; list containers (read-only)</option>
            <?php if (array_filter($all,fn($s)=>is_array($s)&&($s['deployment']??'native')==='container'&&!empty($s['container_name']))): ?>
              <option value="start">Start one registered Minecraft container</option>
              <option value="stop">Stop one registered Minecraft container</option>
              <option value="status">Read one registered Minecraft container status</option>
              <option value="bedrock-verify">Verify an installed Bedrock container</option>
              <option value="cleanup">Remove managed Bedrock container and registration</option>
            <?php endif; ?>
          </select>
        </label>
        <label>Target (for status, Start, Stop, verification and cleanup)
          <select name="server_id">
            <option value="">No target — authorization test</option>
            <?php foreach($all as $targetId=>$targetRow): ?>
              <?php if (is_array($targetRow)&&($targetRow['deployment']??'native')==='container'&&!empty($targetRow['container_name'])): ?>
                <option value="<?= h($targetId) ?>" data-server-name="<?= h($targetRow['name']??$targetId) ?>"><?= h($targetRow['name']??$targetId) ?> — <?= h($targetRow['container_name']) ?></option>
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
      <div class="mc-dsm-cleanup-options" data-dsm-cleanup hidden>
        <p><strong>Remove Minecraft container</strong>: stops it if running, removes only this mcmanager-created Bedrock container and its registration. The Docker image stays available for other servers.</p>
        <label>Type the server name to confirm
          <input name="confirm_name" maxlength="64" placeholder="Exact server name" disabled>
        </label>
        <label class="check"><input name="delete_world_files" type="checkbox" value="1" disabled>
          Also permanently delete this server's managed world and configuration files (optional)
        </label>
        <label data-dsm-world-confirm hidden>To delete world files, type DELETE FILES:
          <input name="confirm_world_delete" maxlength="12" placeholder="DELETE FILES" disabled>
        </label>
        <p class="hint">Only the selected Bedrock container can be deleted. Unrelated containers and Docker images are never removed.</p>
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
<?php endif; ?>

<section class="panel spaced" data-manager-servers>
  <h2>Your Minecraft servers</h2>
  <p class="hint">Green = running, red = stopped, yellow = needs attention or a status check. Select a server below; maintenance options are collapsed until needed.</p>
  <?php if (!$all): ?><p class="empty">No Minecraft servers registered yet. Install one below; advanced third-party local server import is optional under Settings.</p><?php endif; ?>
  <?php foreach($all as $id=>$row):
    $state=manager_running_state((string)$id);
    $pending=($row['install_state']??'')==='pending';
    $health=manager_server_status($row,$state,$workerOnline);
    $dsmOnly=$platform==='dsm'&&($row['control_mode']??'')==='dsm-on-demand';
    if ($dsmOnly) $health=dsm_observed_health($row);
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
          <?php if (($row['install_state']??'ready')==='ready'): ?>
            <button type="button" class="mc-action-start" data-dsm-quick-action="start" <?= $health['stopped']?'':'hidden' ?>>Start</button>
            <button type="button" class="subtle" data-dsm-quick-action="stop" <?= $health['running']?'':'hidden' ?>>Stop</button>
            <button type="button" class="subtle" data-dsm-quick-action="status">Check status</button>
          <?php endif; ?>
          <?php if (dsm_cleanup_eligible($row,(string)$id)): ?>
            <a class="subtle" href="?tab=manager&amp;resume_bedrock=<?= h($id) ?>#mc-create-bedrock">Verify / resume Bedrock</a>
          <?php endif; ?>
          <a class="subtle" href="?tab=manager&amp;dsm_server=<?= h($id) ?>#mc-dsm-on-demand" data-dsm-open="<?= h($id) ?>" data-dsm-operation="bedrock-verify">Verify / manage container</a>
          <?php if (dsm_cleanup_eligible($row,(string)$id)): ?>
            <a class="subtle danger-text" href="?tab=manager&amp;dsm_server=<?= h($id) ?>#mc-dsm-on-demand" data-dsm-open="<?= h($id) ?>" data-dsm-operation="cleanup">Remove container</a>
            <a class="subtle" href="#mc-forget-<?= h($id) ?>" data-dsm-forget="<?= h($id) ?>">Forget entry</a>
          <?php endif; ?>
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
      <?php if ($dsmOnly && ($row['install_state']??'ready')==='ready'): ?>
        <form method="post" class="mc-dsm-quick-form" data-dsm-quick-form hidden autocomplete="off">
          <?php hidden_csrf(); ?>
          <input type="hidden" name="action" value="dsm_quick">
          <input type="hidden" name="server_id" value="<?= h($id) ?>">
          <input type="hidden" name="dsm_operation" value="status">
          <strong data-dsm-quick-title>Authorize DSM action</strong>
          <p class="hint">One-time DSM authorization for this server only. The password, OTP and DSM session are discarded after the action.</p>
          <div class="form-row">
            <label>DSM username<input name="dsm_account" required maxlength="64" autocomplete="off"></label>
            <label>DSM password<input name="dsm_password" type="password" required maxlength="1024" autocomplete="new-password"></label>
            <label>One-time code (optional)<input name="dsm_otp" inputmode="numeric" maxlength="8" autocomplete="off"></label>
          </div>
          <div class="mc-dsm-quick-buttons">
            <button type="submit" class="primary" data-dsm-quick-submit>Authorize status check</button>
            <button type="button" class="subtle" data-dsm-quick-cancel>Cancel</button>
          </div>
        </form>
        <p class="hint mc-dsm-quick-feedback" data-dsm-quick-feedback role="status" hidden></p>
      <?php endif; ?>
      <?php if ($dsmOnly && dsm_cleanup_eligible($row,(string)$id)): ?>
        <details id="mc-forget-<?= h($id) ?>" class="mc-stale-server">
          <summary>Remove obsolete mcmanager entry (keep container and files)</summary>
          <p class="hint">Use this if the container or image was already removed in Container Manager. This removes only the mcmanager listing; it does not touch any Docker containers, images or world files.</p>
          <form method="post" data-confirm="Forget this Bedrock server entry? The container, Docker image and world files will NOT be deleted.">
            <?php hidden_csrf(); ?>
            <input type="hidden" name="action" value="dsm_forget_server">
            <input type="hidden" name="server_id" value="<?= h($id) ?>">
            <label>Confirm exact server name <input name="confirm_name" required maxlength="64" placeholder="<?= h($row['name']??$id) ?>"></label>
            <button type="submit" class="subtle danger-text">Forget server entry only</button>
          </form>
        </details>
      <?php endif; ?>
      <details class="mc-server-detail">
        <summary>Details, updates and maintenance</summary>
        <p class="hint">Server storage: <code><?= h($row['path']??'') ?></code></p>
        <?php if ($detected['source']!==''): ?><p class="hint">Minecraft version source: <?= h($detected['source']) ?></p><?php endif; ?>
        <?php if (($row['deployment']??'native')==='native' && $platform==='windows' &&
            ($row['install_state']??'ready')==='ready'): ?>
          <form method="post" class="mc-windows-autostart">
            <?php hidden_csrf(); ?>
            <input type="hidden" name="action" value="manager_autostart">
            <input type="hidden" name="server_id" value="<?= h($id) ?>">
            <label class="check"><input type="checkbox" name="auto_start" value="1"
              <?= !empty($row['auto_start'])?'checked':'' ?>>
              Restart this server automatically after Windows or the mcmanager service restarts</label>
            <button type="submit" class="subtle" <?= !$workerOnline?'disabled':'' ?>>Save restart preference</button>
          </form>
          <p class="hint">Off by default. Start the server once after enabling to arm recovery.
          An intentional Stop disarms it until you select Start again. The worker does not restart unknown or externally owned processes.</p>
          <p class="hint">Recovery: <?= !empty($row['auto_start'])?
            (manager_native_resume_requested((string)$id)?'Enabled and armed':'Enabled, not armed'):'Disabled' ?></p>
        <?php elseif (($row['deployment']??'native')==='native' && $platform==='linux'): ?>
          <p class="hint">Independent Linux supervisor recovery: <?= manager_native_resume_requested((string)$id)?'Requested':'Not requested' ?></p>
        <?php endif; ?>
      <?php if (!empty($state['players_online']) || isset($state['players_max'])): ?>
        <p class="muted">Players <?= h($state['players_online']??0) ?> / <?= h($state['players_max']??0) ?><?= !empty($state['server_version'])?' · '.h($state['server_version']):'' ?></p>
      <?php endif; ?>
      <?php if (isset($state['cpu_percent']) || isset($state['memory_mib']) || isset($state['memory'])): ?>
        <p class="muted">CPU <?= h($state['cpu_percent']??'–') ?><?= isset($state['cpu_percent']) && is_numeric($state['cpu_percent'])?'%':'' ?> · RAM <?= h($state['memory']??(isset($state['memory_mib'])?$state['memory_mib'].' MiB':'Unavailable')) ?></p>
      <?php endif; ?>
      <?php if (($row['install_state']??'')==='failed'): ?>
        <?php if ($workerOnline && $platform==='windows' &&
            ($row['edition']??'')==='bedrock' && ($row['deployment']??'')==='native'): ?>
          <form method="post" class="inline-form">
            <?php hidden_csrf(); ?>
            <input type="hidden" name="action" value="manager_retry_failed_bedrock">
            <input type="hidden" name="server_id" value="<?= h($id) ?>">
            <button class="primary">Retry Bedrock installation</button>
          </form>
          <p class="hint">Retry uses the same managed server entry and requires an empty incomplete installation folder. Existing files are never removed or overwritten.</p>
        <?php endif; ?>
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
        <?php foreach(['status'=>'Refresh','adopt'=>'Adopt','backup'=>'Create backup']+(
            ($row['edition']??'java')==='bedrock' && ($row['deployment']??'native')==='native'
            ? [] : ['check_updates'=>'Check updates']
        ) as $operation=>$label): ?>
          <button class="subtle" name="operation" value="<?= h($operation) ?>"><?= h($label) ?></button>
        <?php endforeach; ?>
      </form>
      <?php if (($row['edition']??'java')==='bedrock' && ($row['deployment']??'native')==='native'): ?>
        <p class="hint">Native Bedrock software updates are not yet supported. The installation and its worlds remain untouched until a verified updater is available.</p>
      <?php else: ?>
        <form method="post" class="inline-form spaced-small">
          <?php hidden_csrf(); ?><input type="hidden" name="action" value="manager_action">
          <input type="hidden" name="server_id" value="<?= h($id) ?>">
          <label>Target version <input name="version" placeholder="latest or release number" maxlength="48"></label>
          <button class="subtle" name="operation" value="update" data-confirm="Update this server after stopping it and taking a backup?">Update software</button>
        </form>
      <?php endif; ?>
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
            Use a private Netty native-library directory within this server<?php if ($platform==='dsm'): ?> (recommended on DSM when /tmp is noexec)<?php endif; ?>
          </label>
          <button class="subtle" type="submit">Save startup settings</button>
        </form>
        <p class="hint">Supported extra flags include G1GC, MaxGCPauseMillis, processor/thread counts, AlwaysPreTouch, encoding and timezone. Heap is configured above; JVM agents, classpath changes and shell commands are not accepted. Native access suppresses the JNA restricted-method warning but does not remove the separate JOML sun.misc.Unsafe deprecation warning. For the "Server empty ... pausing" message, change <code>pause-when-empty-seconds</code> in Server Properties instead.</p>
      </details>
      <?php endif; ?>
      <details class="manager-backups">
        <summary>Backups and restoration (<?= count(manager_backup_files((string)$id)) ?> snapshots)</summary>
        <p class="hint">Backups require a stopped server. Restore verifies SHA-256, creates a safety snapshot and swaps server folders.
        <?php if ($platform==='dsm'): ?>Synology ACLs are not preserved; imported folders may require permission repair.
        <?php elseif ($platform==='windows'): ?>Custom Windows NTFS access control entries are not guaranteed to survive folder replacement; imported folders may require permission review.
        <?php else: ?>Custom Linux permissions and extended ACLs are not guaranteed to survive folder replacement; imported folders may require permission review.<?php endif; ?>
        Older TAR archives are retained but not restored directly.</p>
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
    $s=$selectedJob['result']['system'];
    // Legacy job reports predate platform detection. Interpret those from
    // their recorded OS, not by the viewer's Docker installation.
    $diagnosticsPlatform=(string)($s['platform']??(
        ($s['os']??'')==='Windows'?'windows':$platform
    ));
    $diagnosticsDsm=$diagnosticsPlatform==='dsm';
    $osTitle=(string)($s['os_name']??'');
    if ($osTitle==='')$osTitle=$diagnosticsPlatform==='dsm'?'Synology DSM':ucfirst($diagnosticsPlatform);
    $nativeMode=(string)($s['native_supervision']??(
        !empty($s['persistent_native_supported'])?'detached':'worker'
    ));
  ?>
    <h3>Local host hardware</h3>
    <div class="two-column">
      <div class="status-line"><span>Operating system</span><strong><?= h($osTitle) ?></strong></div>
      <div class="status-line"><span>CPU architecture</span><strong><?= h($s['architecture']??'Unavailable') ?></strong></div>
      <?php if (!empty($s['cpu_model'])): ?>
        <div class="status-line"><span>Processor</span><strong><?= h($s['cpu_model']) ?></strong></div>
      <?php endif; ?>
      <div class="status-line"><span>Total RAM</span><strong><?= h(!empty($s['ram_total'])?round($s['ram_total']/1073741824,1).' GiB':'Unavailable') ?></strong></div>
      <div class="status-line"><span>Available RAM</span><strong><?= h(isset($s['ram_available'])&&$s['ram_total']>0?round($s['ram_available']/1073741824,1).' GiB':'Unavailable') ?></strong></div>
      <div class="status-line"><span>Available disk</span><strong><?= h(isset($s['free_storage'])&&is_numeric($s['free_storage'])?round($s['free_storage']/1073741824,1).' GiB':'Unavailable') ?></strong></div>
      <div class="status-line"><span>Native server supervision</span><strong><?php
        if ($nativeMode==='detached') echo 'Persistent Linux process supervisor';
        elseif ($diagnosticsPlatform==='windows') echo 'Local Windows worker (must remain running)';
        else echo 'Local worker (must remain running)';
      ?></strong></div>
      <div class="status-line"><span>Managed storage</span><code><?= h($s['instances_root']??'') ?></code></div>
    </div>
    <?php if ($diagnosticsDsm): ?>
      <h3>Synology DSM Container Manager</h3>
      <div class="two-column">
        <div class="status-line"><span>Container Manager</span><strong><?= !empty($s['container_manager_installed'])?'Installed':'Not detected' ?></strong></div>
        <div class="status-line"><span>Docker daemon</span><strong><?= h($s['docker']??'Not checked') ?></strong></div>
        <?php if (isset($s['docker_cli'])): ?>
          <div class="status-line"><span>Docker CLI executable</span><code><?= h($s['docker_cli']) ?></code></div>
          <div class="status-line"><span>Docker socket</span><code><?= h($s['docker_socket']??'Unknown') ?></code></div>
          <div class="status-line"><span>Worker account</span><strong><?= h($s['docker_worker_user']??'Unknown') ?></strong></div>
        <?php endif; ?>
      </div>
      <?php if (!empty($s['docker_error'])): ?>
        <p class="hint">Docker error: <?= h($s['docker_error']) ?></p>
      <?php endif; ?>
      <?php if (empty($s['container_supported'])): ?>
        <p class="hint">On DSM, Container Manager can be running while the restricted mcmanager worker lacks Docker socket access. Check permissions only if Docker-worker integration is required. Temporary DSM authorization remains a separate option; never make Docker's socket world-writable.</p>
      <?php endif; ?>
    <?php endif; ?>
    <h3>Local Java runtimes</h3>
    <p class="hint">Java Edition needs a compatible Java runtime. Bedrock Dedicated Server does not use Java. Managed Java installations are kept separate from system Java.</p>
    <?php foreach (($s['java']??[]) as $java):
      $fullVersion=(string)($java['version']??'');
      if ($fullVersion==='' && preg_match('/(?:openjdk|java)\s+version\s+"([^"]+)"/i',(string)($java['raw']??''),$match))
          $fullVersion=$match[1];
      $validVersion=($java['code']??1)===0 && (int)($java['major']??0)>0;
    ?>
      <div class="status-line"><span><code><?= h($java['path']??'') ?></code></span><strong><?= $validVersion?'Java '.h($fullVersion!==''?$fullVersion:$java['major']):'Version unavailable' ?> <?= !empty($java['managed'])?'(mcmanager-managed)':'(system)' ?></strong></div>
    <?php endforeach; ?>
    <?php if (empty($s['java'])): ?>
      <p class="muted">No Java runtime found. For Java Edition, install a compatible runtime using the Java management controls.</p>
    <?php endif; ?>
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



<details id="mc-create-server" class="mc-feature-group">
<summary>Create a Minecraft server</summary>
<section class="panel spaced">
  <h2>Install a new Minecraft server</h2>
  <p class="muted">Each server gets separate persistent storage, ports, limits and lifecycle controls. A working management service and compatible host software are required.</p>
  <?php if ($platform==='dsm' && !$dockerAvailable):
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
  <form method="post" enctype="multipart/form-data" class="manager-install">
    <?php hidden_csrf(); ?><input type="hidden" name="action" value="manager_install">
    <div class="form-row">
      <label>Server name <input name="name" placeholder="Family server" maxlength="64" required></label>
      <label>Edition <select name="edition"><option value="java">Java Edition</option><?php if ($platform==='windows'||$dockerAvailable): ?><option value="bedrock">Bedrock Edition</option><?php endif; ?></select></label>
    </div>
    <div class="form-row">
      <div class="manager-software-field" data-software-help>
        <label for="mc-install-software">Software</label>
        <div class="manager-software-picker">
          <select id="mc-install-software" name="software">
            <option value="paper">Paper (Java)</option><option value="vanilla">Vanilla (Java)</option>
            <?php if ($platform==='windows'): ?>
            <option value="bedrock">Bedrock Dedicated Server (native)</option>
            <?php elseif ($dockerAvailable): ?>
            <option value="spigot">Spigot (container)</option><option value="bukkit">CraftBukkit (container)</option>
            <option value="fabric">Fabric (container)</option><option value="forge">Forge (container)</option>
            <option value="neoforge">NeoForge (container)</option><option value="purpur">Purpur (container)</option>
            <option value="folia">Folia (container)</option><option value="bedrock">Bedrock Dedicated Server</option>
            <?php endif; ?>
          </select>
          <button type="button" class="manager-software-info" data-software-info
            aria-label="About the selected Minecraft server software" aria-expanded="false"
            aria-controls="mc-software-description" title="What is this server software?">?</button>
        </div>
        <div class="manager-software-guide" id="mc-software-description" data-software-description
             role="region" aria-label="Server software explanation" hidden>
          <strong data-software-title>Paper</strong>
          <p data-software-summary></p>
          <a data-software-docs href="https://papermc.io/software/paper/"
             target="_blank" rel="noopener noreferrer">Publisher documentation</a>
          <button type="button" data-software-close class="manager-software-close"
                  aria-label="Close server software explanation">Close</button>
        </div>
      </div>
      <label>Installation <select name="deployment"><option value="native">Native local server (no Docker)</option><?php if ($dockerAvailable): ?><option value="container">DSM Container Manager</option><?php endif; ?></select></label>
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
      <label class="check"><input type="checkbox" name="netty_workdir" value="1" checked> Use server-local Netty native-library directory (recommended for restricted temporary directories)</label>
    </details>
    <?php if ($platform==='windows'): ?>
      <details class="manager-install-launch" data-windows-bedrock-upload hidden>
        <summary>Download blocked? Upload the official Bedrock server ZIP instead</summary>
        <p class="hint">Download the Windows ZIP from <a href="https://www.minecraft.net/en-us/download/server/bedrock" target="_blank" rel="noopener noreferrer">Mojang's official Bedrock server page</a> and upload it here. mcmanager still creates and manages its own new server folder. The ZIP must match Mojang's latest published server filename; uploaded executables cannot be independently publisher-verified by SHA-256.</p>
        <label>Windows Bedrock server ZIP (optional)
          <input name="bedrock_archive" type="file" accept=".zip">
        </label>
      </details>
    <?php endif; ?>
    <label class="check"><input name="eula" type="checkbox" value="1" required>
      I accept the Minecraft <a href="https://www.minecraft.net/eula" target="_blank" rel="noopener noreferrer">End User License Agreement</a>.</label>
    <button class="primary" <?= !$workerOnline?'disabled':'' ?>>Create server</button>
  </form>
  <p class="hint"><?php if ($platform==='windows'): ?>Windows installs Java Vanilla/Paper or the official native Bedrock Dedicated Server locally, without virtualization or Docker. Java uses a compatible managed or existing runtime; Bedrock needs no Java.<?php elseif ($platform==='linux'): ?>Linux installs native Vanilla/Paper Java locally; native Bedrock installation is being certified separately.<?php else: ?>DSM native Vanilla/Paper and the dedicated Bedrock Container Manager wizard are independent.<?php endif; ?> All newly created server data stays under mcmanager-owned storage.</p>
</section>

</details>

<details class="mc-feature-group">
<summary>Java runtimes and installation</summary>
<section class="panel spaced">
  <div class="panel-title"><div><h2>Java runtime management</h2>
    <p class="muted">Install or update Eclipse Temurin JREs side by side without changing the host's system Java. Archives are verified and matched to the local OS and CPU.</p></div>
    <strong><?= h(php_uname('m')) ?></strong>
  </div>
  <div class="manager-install-options">
    <div class="manager-method">
      <h3>1. Known official downloads</h3>
      <p class="hint">Latest official JRE for this local computer. The worker resolves its current upstream download link and SHA-256.</p>
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
      <p class="hint">Upload the matching official Temurin JRE <?= $platform==='windows'?'<code>.zip</code>':'<code>.tar.gz</code>' ?> archive. The worker rejects an incorrect CPU architecture or checksum.</p>
      <form method="post" enctype="multipart/form-data">
        <?php hidden_csrf(); ?>
        <input type="hidden" name="action" value="manager_install_java">
        <input type="hidden" name="source" value="upload">
        <label>Java release <select name="major"><option value="21">Java 21</option><option value="25">Java 25</option><option value="17">Java 17</option></select></label>
        <label>Temurin archive <input name="java_archive" type="file" accept="<?= $platform==='windows'?'.zip':'.gz,.tgz,.tar.gz' ?>" required></label>
        <button class="subtle" <?= !$workerOnline?'disabled':'' ?>>Upload and install</button>
      </form>
    </div>
  </div>
  <p class="hint">Browser upload limit: PHP <?= h(ini_get('upload_max_filesize')) ?> per file / <?= h(ini_get('post_max_size')) ?> per request. <?php if ($platform==='dsm'): ?>For larger JRE uploads, adjust DSM Web Station → Script Language Settings → mcconfig PHP profile.<?php else: ?>For larger JRE uploads, adjust upload_max_filesize and post_max_size in the local PHP configuration.<?php endif; ?> Java Edition needs a compatible runtime; native Bedrock does not need Java.</p>
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
