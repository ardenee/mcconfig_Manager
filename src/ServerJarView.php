<?php
/** Per-server native Java JAR installer, included only for an authenticated session. */
$jarTargets=[];
foreach ($all as $sid=>$record) {
    if (!is_array($record) || ($record['edition']??'java')!=='java' ||
        ($record['deployment']??'native')!=='native' ||
        in_array(($record['install_state']??'ready'),['pending','failed'],true)) continue;
    $jarTargets[(string)$sid]=$record;
}
$jarNative=isset($jarTargets[$selected]);
?>
<section class="panel">
  <h2>Minecraft server software</h2>
  <form method="get" class="manager-server-picker" data-server-picker>
    <input type="hidden" name="tab" value="jar">
    <label>Destination Java server
      <select name="server" data-auto-submit aria-label="Select destination Minecraft server">
        <?php foreach($jarTargets as $id=>$row): ?>
          <option value="<?= h($id) ?>" <?= $id===$selected?'selected':'' ?>><?= h($row['name']??$id) ?> — <?= h($row['software']??'Java') ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="subtle" <?= !$jarTargets?'disabled':'' ?>>Select server</button>
  </form>
  <?php if (!$jarNative): ?>
    <div class="alert warning">This server is not a native Java server, or its installation is pending/failed. Select a native Java destination above. Containerized Java servers update their image through Server Manager instead.</div>
  <?php else:
    $jar=trim((string)($server['jar']??'')) ?: 'server.jar';
    $jarPath=preg_match('/^[A-Za-z0-9_.-]+\.jar$/iD',$jar)?server_file($server,$jar):'';
    $detected=jar_detect_version($server);
    $version=$detected['version'];
    $required=jar_java_required($version);
    $software=strtolower((string)($server['software']??'vanilla'));
    $supportsCatalog=in_array($software,['vanilla','paper'],true);
    $workerAvailable=manager_online();
    $genericUploadAllowed=!$workerAvailable && !is_dir('/var/packages/mcconfig');
  ?>
    <p class="hint">All JAR operations below target only <strong><?= h($server['name']) ?></strong>. Stop Minecraft before changing its executable, then restart after installing. Each replacement preserves a timestamped copy of the old JAR.</p>
    <div class="status-line"><span>Server folder</span><code><?= h($server['path']) ?></code></div>
    <div class="status-line"><span>Configured executable</span><strong><?= h($jar) ?></strong></div>
    <div class="status-line"><span>JAR file</span><strong><?= $jarPath!=='' && is_file($jarPath) ? h(round(filesize($jarPath)/1048576,1).' MiB') : 'Not installed' ?></strong></div>
    <div class="status-line"><span>Minecraft version</span><strong><?= h($version?:'Unknown — not detected') ?></strong></div>
    <p class="hint">Version source: <?= h($detected['source']?:'No version metadata') ?><?php if ($required): ?> · Generally requires Java <strong><?= $required ?></strong> (verify modded-server requirements independently)<?php endif; ?></p>
    <?php if (!$workerAvailable): ?><div class="alert warning">The management worker is offline. Official catalog and URL installs are unavailable. <?= $genericUploadAllowed?'On a generic web host, you can still manually upload a JAR after explicitly confirming the server is stopped.':'On a DSM package install, start the worker for safe JAR operations.' ?></div><?php endif; ?>
    <div class="manager-install-options">
      <div class="manager-method">
        <h3>1. Known official versions</h3>
        <p class="hint">Use official Mojang Vanilla or stable Paper releases, matched to this server's registered implementation.</p>
        <form method="post">
          <?php hidden_csrf(); ?><input type="hidden" name="action" value="server_jar_install">
          <input type="hidden" name="server_id" value="<?= h($selected) ?>">
          <input type="hidden" name="source" value="catalog">
          <div class="status-line"><span>Software</span><strong><?= h(ucfirst($software)) ?></strong></div>
          <label>Minecraft release
            <input name="version" placeholder="latest stable or e.g. 1.21.4" maxlength="48" value="latest">
          </label>
          <label class="check"><input type="checkbox" name="stopped" required> I confirmed the server is stopped.</label>
          <button class="primary" <?= !$workerAvailable||!$supportsCatalog?'disabled':'' ?>>Download and install official JAR</button>
        </form>
        <?php if (!$supportsCatalog): ?><p class="hint">Automatic official catalog downloads currently support Vanilla and Paper only. Other Java implementations can use upload after checking compatibility.</p><?php endif; ?>
      </div>
      <div class="manager-method">
        <h3>2. Install by HTTPS URL</h3>
        <p class="hint">Direct official Mojang or Paper download URL, with the published SHA-1 or SHA-256 checksum.</p>
        <form method="post">
          <?php hidden_csrf(); ?><input type="hidden" name="action" value="server_jar_install">
          <input type="hidden" name="server_id" value="<?= h($selected) ?>">
          <input type="hidden" name="source" value="url">
          <label>Direct official JAR URL <input type="url" name="url" required maxlength="1600" placeholder="https://piston-data.mojang.com/..."></label>
          <label>Expected publisher checksum <input name="checksum" required maxlength="64" minlength="40" placeholder="SHA-1 (40) or SHA-256 (64 hex characters)"></label>
          <label>Minecraft release <input name="version" placeholder="e.g. 1.21.4" maxlength="48"></label>
          <label class="check"><input type="checkbox" name="stopped" required> I confirmed the server is stopped.</label>
          <button class="subtle" <?= !$workerAvailable?'disabled':'' ?>>Download verified JAR</button>
        </form>
      </div>
      <div class="manager-method">
        <h3>3. Upload a server JAR</h3>
        <p class="hint">Upload an official or trusted Minecraft server JAR from your computer. The worker validates the ZIP/JAR and records the uploaded SHA-256.</p>
        <form method="post" enctype="multipart/form-data">
          <?php hidden_csrf(); ?><input type="hidden" name="action" value="<?= $genericUploadAllowed?'jar_upload':'server_jar_install' ?>">
          <input type="hidden" name="server_id" value="<?= h($selected) ?>">
          <input type="hidden" name="source" value="upload">
          <input type="hidden" name="generic_upload" value="<?= $genericUploadAllowed?'1':'0' ?>">
          <label>Server JAR <input type="file" name="jar_file" accept=".jar" required></label>
          <label>Minecraft version (if not embedded) <input name="version" maxlength="48" placeholder="e.g. 1.21.4"></label>
          <label class="check"><input type="checkbox" name="stopped" required> I confirmed the server is stopped.</label>
          <button class="subtle" <?= !$workerAvailable&&!$genericUploadAllowed?'disabled':'' ?>>Upload and install JAR</button>
        </form>
      </div>
    </div>
    <p class="hint">On DSM, PHP file upload limits also apply. Installing a JAR does not install Java; use Server Manager → Java runtime management for the runtime indicated above.</p>
  <?php endif; ?>
</section>
