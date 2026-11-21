<?php
// Each plugin operation is tied to this explicitly selected server ID.
$pluginTargets=[];
foreach($all as $id=>$row) {
    if (is_array($row) && ($row['edition']??'java')==='java' &&
        !in_array(($row['install_state']??'ready'),['pending','failed'],true)) {
        $pluginTargets[(string)$id]=$row;
    }
}
$enabled=plugin_supported($server);
$pluginWorker=manager_online();
?>
<section class="panel">
  <div class="panel-title"><div><h2>Server plugins</h2>
    <p class="muted">Select the destination Minecraft server before changing its plugins.</p></div>
    <span class="mc-status mc-status--<?= $enabled?'running':'warning' ?>"><?= $enabled?'Plugin-ready':'Plugin loader not installed' ?></span>
  </div>
  <form method="get" class="manager-server-picker" data-server-picker>
    <input type="hidden" name="tab" value="plugins">
    <label>Destination server
      <select name="server" data-auto-submit aria-label="Choose plugin destination server">
        <?php foreach($pluginTargets as $id=>$row): ?>
          <option value="<?= h($id) ?>" <?= $id===$selected?'selected':'' ?>><?= h($row['name']??$id) ?> — <?= h(ucfirst($row['software']??'Paper')) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="subtle" <?= !$pluginTargets?'disabled':'' ?>>Select server</button>
  </form>
  <?php if (!$pluginTargets): ?><div class="alert warning">No Java servers are registered. Create or import a Java server first.</div><?php endif; ?>
  <div class="status-line"><span>Current destination</span><strong><?= h($server['name']??$selected) ?></strong></div>
  <?php if (!$enabled): ?>
    <?php if (($server['software']??'')==='vanilla'): ?>
      <div class="mc-plugin-intro">
        <h3>Vanilla Java does not load plugin JARs</h3>
        <p>Dropping .jar files into a <code>plugins/</code> directory requires compatible software such as Paper. Vanilla supports world datapacks instead; those are not Bukkit plugins.</p>
        <?php if (($server['deployment']??'native')==='native'): ?>
          <details class="mc-feature-group mc-vanilla-upgrade">
            <summary>Enable plugin support by switching to Paper</summary>
            <p>For a stopped mcmanager-created Vanilla server, this makes a complete world and configuration backup, downloads the matching verified stable Paper server JAR and switches the server implementation. The world is not altered before the next startup.</p>
            <p class="hint">Paper may behave differently from Vanilla and individual plugins can have version requirements. If no stable Paper release matches your Minecraft version, nothing is changed. Imported servers cannot be automatically converted.</p>
            <form method="post" class="mc-paper-upgrade-form" data-confirm="Back up this server and replace its Vanilla JAR with Paper? The server must be stopped.">
              <?php hidden_csrf(); ?>
              <input type="hidden" name="action" value="plugin_enable_paper">
              <input type="hidden" name="server_id" value="<?= h($selected) ?>">
              <label>Type exact destination server name
                <input name="confirm_name" required autocomplete="off" placeholder="<?= h($server['name']??'') ?>">
              </label>
              <label class="check"><input type="checkbox" name="stopped" value="1" required> I have stopped this Minecraft server and approve the software change.</label>
              <button type="submit" class="primary" <?= !$pluginWorker?'disabled':'' ?>>Backup and switch to Paper</button>
            </form>
          </details>
        <?php else: ?>
          <p class="hint">To enable plugins on a containerized Vanilla server, its container must be reconfigured to use a compatible Paper image. Native server conversion is not available for containers.</p>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="alert warning">This server uses <?= h(ucfirst((string)($server['software']??'unknown'))) ?>, not a Bukkit/Paper plugin loader. Fabric and Forge use a different mod system.</div>
    <?php endif; ?>
  <?php else: ?>
    <p class="hint">Stop the Minecraft server before installing or removing plugins, then start it to apply changes. Plugin JARs execute third-party Java code with the Minecraft server's permissions. Only use trusted publishers; mcmanager cannot guarantee that a plugin is safe or compatible.</p>
    <?php if (!$pluginWorker): ?><div class="alert warning">The management worker is offline. Plugin operations are unavailable.</div><?php endif; ?>
    <h3>Installed plugin files</h3>
    <?php $plugins=plugin_inventory($server); ?>
    <?php if (!$plugins): ?><p class="muted">No plugin JARs found in the server's plugins directory.</p><?php endif; ?>
    <?php foreach($plugins as $plugin): ?>
      <div class="status-line">
        <span><strong><?= h($plugin['name']) ?></strong>
          <small><?= h(round($plugin['size']/1048576,2)) ?> MiB &middot; <?= h(date('Y-m-d H:i',$plugin['updated'])) ?></small></span>
        <form method="post" data-confirm="Remove <?= h($plugin['name']) ?>? A private recovery copy will be kept. The Minecraft server must be stopped.">
          <?php hidden_csrf(); ?>
          <input type="hidden" name="action" value="plugin_remove">
          <input type="hidden" name="server_id" value="<?= h($selected) ?>">
          <input type="hidden" name="filename" value="<?= h($plugin['name']) ?>">
          <button type="submit" class="subtle danger-text" <?= !$pluginWorker?'disabled':'' ?>>Remove plugin</button>
        </form>
      </div>
    <?php endforeach; ?>
    <div class="manager-install-options">
      <div class="manager-method">
        <h3>Install plugin from file</h3>
        <form method="post" enctype="multipart/form-data">
          <?php hidden_csrf(); ?>
          <input type="hidden" name="action" value="plugin_upload">
          <input type="hidden" name="server_id" value="<?= h($selected) ?>">
          <label>Plugin JAR
              <div class="mc-plugin-dropzone" data-plugin-dropzone tabindex="0">
                <strong>Drop plugin .jar here</strong><small>or choose a file</small>
                <input type="file" name="plugin_jar" accept=".jar" required data-plugin-file>
                <small data-plugin-filename>No file selected</small>
              </div>
            </label>
          <button type="submit" class="primary" <?= !$pluginWorker?'disabled':'' ?>>Upload and install</button>
        </form>
        <p class="hint">Upload limit: PHP <?= h(ini_get('upload_max_filesize')) ?>, request <?= h(ini_get('post_max_size')) ?>. The JAR must contain <code>plugin.yml</code> or <code>paper-plugin.yml</code>.</p>
      </div>
      <div class="manager-method">
        <h3>Install plugin from URL</h3>
        <p class="hint">Use a direct HTTPS JAR download from Modrinth's CDN and its published SHA-256 checksum.</p>
        <form method="post">
          <?php hidden_csrf(); ?>
          <input type="hidden" name="action" value="plugin_url">
          <input type="hidden" name="server_id" value="<?= h($selected) ?>">
          <label>Save as (JAR filename) <input name="filename" required maxlength="116" placeholder="ExamplePlugin.jar"></label>
          <label>Direct Modrinth CDN URL <input name="url" type="url" maxlength="1200" required placeholder="https://cdn.modrinth.com/data/.../plugin.jar"></label>
          <label>SHA-256 checksum <input name="sha256" maxlength="64" minlength="64" pattern="[0-9a-fA-F]{64}" required></label>
          <button type="submit" class="subtle" <?= !$pluginWorker?'disabled':'' ?>>Download and install</button>
        </form>
      </div>
    </div>
    <p class="hint">Removing a plugin retains its previous JAR in mcmanager's private recovery storage. Plugin-specific config files and world data are deliberately left untouched. Compatibility and dependencies are not automatically resolved.</p>
  <?php endif; ?>
</section>
