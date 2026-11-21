<?php
// Each plugin operation is tied to this explicitly selected server ID.
$pluginTargets=[];
foreach($all as $id=>$row) {
    if (is_array($row) && plugin_supported($row) &&
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
    <strong><?= $enabled?'Compatible server':'Unsupported server' ?></strong>
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
  <?php if (!$pluginTargets): ?><div class="alert warning">No Bukkit/Paper-compatible registered servers are available. Install or import Paper, Spigot, Bukkit, Purpur or Folia first.</div><?php endif; ?>
  <div class="status-line"><span>Current destination</span><strong><?= h($server['name']??$selected) ?></strong></div>
  <?php if (!$enabled): ?>
    <div class="alert warning">This server implementation is not compatible with Bukkit/Paper plugin JARs. Vanilla and Bedrock do not load them; Fabric and Forge use mods, which require a different manager.</div>
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
          <label>Plugin JAR <input type="file" name="plugin_jar" accept=".jar" required></label>
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
