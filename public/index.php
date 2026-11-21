<?php
declare(strict_types=1);
if (PHP_VERSION_ID < 80200) { http_response_code(500); exit('mcconfig requires PHP 8.2 or newer.'); }
require dirname(__DIR__) . '/src/App.php';
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ini_set('session.cookie_secure', '1');
session_name('mcconfig_session');
session_start();
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; form-action 'self'; base-uri 'self'; frame-ancestors 'none'");
$config = app_config();
$installed = isset($config['password_hash']) && is_string($config['password_hash']);
$authenticated = $installed && ($_SESSION['authenticated'] ?? false) === true;
$tab = request_string($_GET, 'tab', 32);
$tabs = ['overview'=>'Overview', 'properties'=>'Server properties', 'players'=>'Players & access', 'files'=>'Config files', 'worlds'=>'Worlds', 'logs'=>'Server logs', 'jar'=>'JAR management', 'settings'=>'Settings', 'about'=>'About'];
if (!isset($tabs[$tab])) $tab = 'overview';
$message = (string)($_SESSION['flash'] ?? '');
unset($_SESSION['flash']);
$error = '';
$selected = '';
$server = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = '';
    try {
        check_csrf();
        $action = request_string($_POST, 'action', 48);
        if (!$installed && $action === 'setup') {
            $password = request_string($_POST, 'password', 512);
            if (strlen($password) < 12) throw new InvalidArgumentException('Use an administrator password of at least 12 characters.');
            if ($password !== request_string($_POST, 'confirm', 512)) throw new InvalidArgumentException('Passwords do not match.');
            $config = ['password_hash'=>password_hash($password, PASSWORD_DEFAULT), 'servers'=>[]];
            save_config($config);
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            $_SESSION['flash'] = 'Administrator created. Add a Minecraft server under Settings.';
            redirect('settings');
        }
        if ($installed && $action === 'login') {
            $password = request_string($_POST, 'password', 512);
            $tries = (int)($_SESSION['login_tries'] ?? 0);
            $since = (int)($_SESSION['login_since'] ?? 0);
            if ($tries >= 8 && time() - $since < 900) throw new RuntimeException('Too many attempts. Try again in 15 minutes.');
            if (!password_verify($password, $config['password_hash'])) {
                if ($tries === 0 || time() - $since >= 900) { $tries = 0; $since = time(); }
                $_SESSION['login_tries'] = $tries + 1;
                $_SESSION['login_since'] = $since;
                throw new RuntimeException('Incorrect password.');
            }
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            $_SESSION['login_tries'] = 0;
            redirect();
        }
        if (!$authenticated) throw new RuntimeException('Log in to continue.');
        if ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            redirect();
        }
        if ($action === 'add_server') {
            $name = server_name(request_string($_POST, 'name', 80));
            $root = valid_root(request_string($_POST, 'path', 2048));
            $jar = request_string($_POST, 'jar', 128);
            if ($jar !== '' && !preg_match('/^[A-Za-z0-9._-]+\.jar$/iD', $jar)) throw new InvalidArgumentException('Use a simple .jar filename, not a path.');
            $id = bin2hex(random_bytes(6));
            $config['servers'][$id] = ['name'=>$name, 'path'=>$root, 'jar'=>$jar];
            save_config($config);
            $_SESSION['server_id'] = $id;
            $_SESSION['flash'] = 'Server added.';
            redirect('settings');
        }
        if ($action === 'remove_server') {
            $id = request_string($_POST, 'server_id', 64);
            if (!isset($config['servers'][$id])) throw new RuntimeException('Unknown server.');
            unset($config['servers'][$id]);
            save_config($config);
            unset($_SESSION['server_id']);
            $_SESSION['flash'] = 'Server removed from mcconfig; Minecraft files were not deleted.';
            redirect('settings');
        }
        if ($action === 'password_change') {
            if (!password_verify(request_string($_POST, 'current', 512), $config['password_hash'])) throw new RuntimeException('Current password is incorrect.');
            $password = request_string($_POST, 'password', 512);
            if (strlen($password) < 12 || $password !== request_string($_POST, 'confirm', 512)) throw new InvalidArgumentException('New passwords must match and have at least 12 characters.');
            $config['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            save_config($config);
            $_SESSION['flash'] = 'Administrator password updated.';
            redirect('settings');
        }
        $id = request_string($_POST, 'server_id', 64);
        $server = server_from($config, $id);
        $_SESSION['server_id'] = $id;
        if ($action === 'properties_save') {
            $posted = $_POST['props'] ?? [];
            if (!is_array($posted)) throw new InvalidArgumentException('Invalid properties form.');
            properties_save($server, $posted, request_string($_POST, 'new_key', 80), request_string($_POST, 'new_value', 4096));
            $_SESSION['flash'] = 'Properties saved. Restart the Minecraft server to apply changes.';
            redirect('properties');
        }
        if ($action === 'select_world') {
            $name = request_string($_POST, 'world', 255);
            if (!in_array($name, list_worlds($server), true)) throw new InvalidArgumentException('World directory not found.');
            $properties = properties_read($server);
            properties_save($server, ['level-name'=>$name], array_key_exists('level-name', $properties) ? '' : 'level-name', $name);
            $_SESSION['flash'] = 'World selection saved. Restart Minecraft to use it.';
            redirect('worlds');
        }
        if ($action === 'player_add' || $action === 'player_remove') {
            $type = request_string($_POST, 'type', 32);
            player_update($server, $type, $action === 'player_add' ? 'add' : 'remove', $_POST);
            $_SESSION['flash'] = 'Player list updated. Your Minecraft server may need a reload or restart.';
            redirect('players');
        }
        if ($action === 'jar_upload') {
            jar_upload($server, $_FILES['jar_file'] ?? [], isset($_POST['stopped']));
            $_SESSION['flash'] = 'JAR installed. The previous version was backed up when present.';
            redirect('jar');
        }
        if ($action === 'save_text') {
            $body = $_POST['content'] ?? null;
            if (!is_string($body)) throw new InvalidArgumentException('Invalid editor content.');
            $name = request_string($_POST, 'file_key', 80);
            save_editable_text($server, $name, $body);
            $_SESSION['flash'] = 'Configuration file saved with a backup.';
            redirect('files', '&file=' . rawurlencode($name));
        }
        if ($action === 'world_upload') {
            if (!isset($_POST['stopped'])) throw new InvalidArgumentException('Confirm the server is stopped before importing worlds.');
            $name = world_archive_name(request_string($_POST, 'world_name', 80));
            $upload = $_FILES['world_zip'] ?? [];
            if (($upload['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($upload['tmp_name'] ?? '')))
                throw new RuntimeException('World ZIP upload failed; check PHP upload limits.');
            if (($upload['size'] ?? 0) > 536870912) throw new InvalidArgumentException('World ZIP is too large (512 MiB upload limit).');
            import_world_archive($server, $name, (string)$upload['tmp_name']);
            $_SESSION['flash'] = 'World imported. Select it and restart Minecraft when ready.';
            redirect('worlds');
        }
        throw new InvalidArgumentException('Unsupported action.');
    } catch (Throwable $e) {
        $error = $e->getMessage();
        // Do not expose filesystem details in the login screen.
        if (!$authenticated && $installed && $action !== 'login') $error = 'Please log in.';
    }
}

if ($authenticated) {
    $all = is_array($config['servers'] ?? null) ? $config['servers'] : [];
    if (isset($_GET['server']) && is_string($_GET['server']) && isset($all[$_GET['server']])) $_SESSION['server_id'] = $_GET['server'];
    $selected = (string)($_SESSION['server_id'] ?? array_key_first($all) ?? '');
    if (!isset($all[$selected])) $selected = (string)(array_key_first($all) ?? '');
    if ($selected !== '') {
        try { $server = server_from($config, $selected); }
        catch (Throwable $e) { $error = $e->getMessage(); $server = null; }
    }
    if ($server === null && !in_array($tab, ['settings','about'], true)) $tab = 'settings';
}
if ($authenticated && $server && ($_GET['action'] ?? '') === 'download_world') {
    try {
        $world = request_string($_GET, 'world', 255);
        $archive = export_world($server, $world);
        try {
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $world) . '.zip"');
            header('Content-Length: ' . filesize($archive));
            header('X-Content-Type-Options: nosniff');
            readfile($archive);
        } finally { @unlink($archive); }
        exit;
    } catch (Throwable $e) { $error = $e->getMessage(); $tab = 'worlds'; }
}
function hidden_csrf(): void { echo '<input type="hidden" name="csrf" value="'.h(csrf_token()).'">'; }
function hidden_server(string $id): void { echo '<input type="hidden" name="server_id" value="'.h($id).'">'; }
function stat_card(string $label, string $value, string $info = ''): void {
    echo '<section class="metric"><small>'.h($label).'</small><strong>'.h($value).'</strong><span>'.h($info).'</span></section>';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= h($tabs[$tab]) ?> · mcconfig</title>
<link rel="icon" href="assets/mcconfig.png">
<link rel="stylesheet" href="assets/style.css">
<script src="assets/app.js" defer></script>
</head>
<body class="<?= $authenticated ? 'app-mode' : 'auth-mode' ?>">
<?php if (!$authenticated): ?>
<main class="login-shell">
  <div class="login-panel">
    <img src="assets/mcconfig.png" width="76" height="76" alt="mcconfig icon">
    <h1>mcconfig</h1><p class="muted">Minecraft server configuration</p>
    <?php if ($error): ?><div class="alert danger"><?= h($error) ?></div><?php endif; ?>
    <?php if (!$installed): ?>
      <div class="alert warning">First-time setup. Complete this on a trusted network before exposing your web server publicly.</div>
      <form method="post"><?php hidden_csrf(); ?><input type="hidden" name="action" value="setup">
        <label>Administrator password <input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
        <label>Confirm password <input type="password" name="confirm" required minlength="12" autocomplete="new-password"></label>
        <button type="submit" class="primary">Create administrator</button>
      </form>
    <?php else: ?>
      <form method="post"><?php hidden_csrf(); ?><input type="hidden" name="action" value="login">
        <label>Administrator password <input type="password" name="password" required autocomplete="current-password" autofocus></label>
        <button type="submit" class="primary">Sign in</button>
      </form>
    <?php endif; ?>
    <p class="muted footnote">PHP <?= h(PHP_VERSION) ?> · File-based, no database required</p>
  </div>
</main>
<?php else: ?>
<div class="layout">
<aside class="sidebar">
  <a href="index.php" class="brand"><img src="assets/mcconfig.png" width="42" height="42" alt=""><span>mcconfig<small>Server management</small></span></a>
  <div class="sidebar-label">WORKSPACE</div>
  <nav aria-label="Main navigation">
  <?php foreach ($tabs as $key=>$title): ?>
    <a href="?tab=<?= h($key) ?>" class="<?= $tab === $key ? 'active' : '' ?>"><?= h($title) ?></a>
  <?php endforeach; ?>
  </nav>
  <div class="sidebar-bottom"><span>PHP <?= h(PHP_VERSION) ?></span><form method="post"><?php hidden_csrf(); ?><button class="link-button" name="action" value="logout">Sign out</button></form></div>
</aside>
<div class="main-area">
<header class="topbar"><button type="button" class="menu-toggle" data-toggle-nav aria-label="Toggle menu">☰</button><div><span class="crumb">mcconfig / </span><strong><?= h($tabs[$tab]) ?></strong></div>
  <?php if ($server): ?><form method="get" class="server-selector"><input type="hidden" name="tab" value="<?= h($tab) ?>"><select name="server" aria-label="Select Minecraft server" data-auto-submit>
    <?php foreach ($all as $id=>$row): ?><option value="<?= h($id) ?>" <?= $id === $selected ? 'selected' : '' ?>><?= h($row['name'] ?? $id) ?></option><?php endforeach; ?>
  </select></form><?php endif; ?>
</header>
<main class="content">
  <div class="heading"><div><h1><?= h($tabs[$tab]) ?></h1><p><?= h($server['name'] ?? 'Configure your Minecraft installation') ?></p></div></div>
  <?php if ($message): ?><div class="alert success"><?= h($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert danger"><?= h($error) ?></div><?php endif; ?>
  <?php if ($tab === 'settings'): ?>
    <div class="two-column">
    <section class="panel"><h2>Configured servers</h2><p class="muted">Local filesystem access is required. Nothing is changed in Minecraft until you save a setting.</p>
    <?php if (!$all): ?><p class="empty">No Minecraft installations configured yet.</p><?php endif; ?>
    <?php foreach ($all as $id=>$row): ?>
      <div class="server-row"><div><strong><?= h($row['name'] ?? $id) ?></strong><code><?= h($row['path'] ?? '') ?></code><small>JAR: <?= h($row['jar'] ?: 'not configured') ?></small></div>
      <form method="post" data-confirm="Remove this server from mcconfig? Minecraft files will be left alone."><?php hidden_csrf(); ?><input type="hidden" name="action" value="remove_server"><input type="hidden" name="server_id" value="<?= h($id) ?>"><button class="subtle danger-text">Remove</button></form></div>
    <?php endforeach; ?>
    </section>
    <section class="panel"><h2>Add a Minecraft server</h2>
      <form method="post"><?php hidden_csrf(); ?><input type="hidden" name="action" value="add_server">
        <label>Display name <input name="name" placeholder="Minecraft" maxlength="64" required></label>
        <label>Absolute server directory <input name="path" placeholder="/srv/minecraft or /volume1/minecraft" required></label>
        <label>JAR filename (optional) <input name="jar" placeholder="server.jar"></label>
        <p class="hint">The PHP account needs access to server.properties and the chosen player files. Grant only the permissions required.</p>
        <button class="primary">Add server</button>
      </form>
    </section>
    <section class="panel"><h2>Administrator password</h2>
      <form method="post"><?php hidden_csrf(); ?><input type="hidden" name="action" value="password_change">
        <label>Current password <input type="password" name="current" required></label>
        <label>New password <input type="password" name="password" required minlength="12"></label>
        <label>Confirm new password <input type="password" name="confirm" required minlength="12"></label>
        <button class="primary">Change password</button>
      </form>
    </section>
    <section class="panel"><h2>Runtime checks</h2>
      <div class="status-line"><span>PHP version</span><strong><?= h(PHP_VERSION) ?></strong></div>
      <div class="status-line"><span>Private configuration directory</span><strong><?= is_writable(data_dir()) ? 'Writable' : 'Check permissions' ?></strong></div>
      <div class="status-line"><span>Upload maximum</span><strong><?= h(ini_get('upload_max_filesize')) ?></strong></div>
      <div class="status-line"><span>Post maximum</span><strong><?= h(ini_get('post_max_size')) ?></strong></div>
      <p class="hint">A web-hosting installation can manage only Minecraft files accessible to that host. No shell execution or remote server controls are used.</p>
    </section></div>
  <?php elseif ($tab === 'about'): ?>
    <section class="panel prose"><h2>mcconfig</h2><p>Modern PHP interface for managing Minecraft Java Edition configuration files, player permissions, worlds, server logs and local JAR uploads.</p>
      <p>The original application was released for older Synology DSM systems. This edition has been rewritten for generic PHP web hosting, with an optional DSM 7.2+ package built from the same source.</p>
      <p>Changes are saved to files directly. Stop Minecraft before replacing a JAR; restart it when required to apply server configuration changes. Modern player JSON lists require UUIDs.</p>
      <p><a href="https://github.com/ardenee/mcconfig" target="_blank" rel="noopener noreferrer">GitHub project</a></p></section>
  <?php elseif ($server): ?>
    <?php
      $props = properties_read($server);
      $worlds = list_worlds($server);
      [$logname, $log] = log_text($server);
    ?>
    <?php if ($tab === 'overview'): ?>
      <div class="metrics"><?php
        stat_card('Configuration', is_file(server_file($server,'server.properties')) ? 'Found' : 'Missing', 'server.properties');
        stat_card('Worlds', (string)count($worlds), 'Detected level.dat folders');
        stat_card('Server log', $logname !== '' ? 'Found' : 'Missing', $logname ?: 'No log configured');
        stat_card('Properties', (string)count($props), 'Editable keys');
      ?></div>
      <div class="two-column">
        <section class="panel"><div class="panel-title"><h2>Server information</h2><a href="?tab=properties">Edit properties</a></div>
          <?php foreach (['motd'=>'Server message', 'server-port'=>'Port', 'max-players'=>'Max players', 'gamemode'=>'Game mode', 'difficulty'=>'Difficulty', 'level-name'=>'Active world'] as $key=>$label): ?>
          <div class="status-line"><span><?= h($label) ?></span><strong><?= h($props[$key] ?? '—') ?></strong></div>
          <?php endforeach; ?>
        </section>
        <section class="panel"><div class="panel-title"><h2>Detected worlds</h2><a href="?tab=worlds">View worlds</a></div>
          <?php if (!$worlds): ?><p class="empty">No worlds with a level.dat file were found.</p><?php endif; ?>
          <?php foreach (array_slice($worlds,0,12) as $world): ?><div class="status-line"><span><?= h($world) ?></span><strong><?= ($props['level-name'] ?? 'world') === $world ? 'Selected' : '' ?></strong></div><?php endforeach; ?>
        </section>
        <section class="panel wide"><div class="panel-title"><h2>Recent log lines</h2><a href="?tab=logs">View logs</a></div><pre class="log-preview"><?= h(implode("\n", array_slice(explode("\n", $log),-18))) ?></pre></section>
      </div>
    <?php elseif ($tab === 'properties'): ?>
      <section class="panel"><div class="panel-title"><div><h2>server.properties</h2><p class="muted">All current keys are shown, including options added in newer Minecraft versions.</p></div><input id="property-search" placeholder="Filter properties…" aria-label="Filter properties"></div>
        <?php if (!is_file(server_file($server, 'server.properties'))): ?><p class="alert warning">No server.properties found at the configured server path.</p><?php else: ?>
        <form method="post"><?php hidden_csrf(); hidden_server($selected); ?><input type="hidden" name="action" value="properties_save">
          <div class="properties-grid">
          <?php foreach ($props as $key=>$value): ?>
            <label class="property" data-property="<?= h(strtolower($key)) ?>"><span><?= h($key) ?></span>
            <?php if (in_array(strtolower($value), ['true','false'], true)): ?>
              <select name="props[<?= h($key) ?>]"><option value="true" <?= strtolower($value) === 'true' ? 'selected' : '' ?>>true</option><option value="false" <?= strtolower($value) === 'false' ? 'selected' : '' ?>>false</option></select>
            <?php else: ?><input name="props[<?= h($key) ?>]" value="<?= h($value) ?>" maxlength="4096" <?= str_contains(strtolower($key), 'password') ? 'autocomplete="off"' : '' ?>><?php endif; ?>
            </label>
          <?php endforeach; ?>
          </div><div class="add-property"><h3>Add property</h3><div class="form-row"><input name="new_key" placeholder="property-name"><input name="new_value" placeholder="Value"></div></div>
          <div class="actions"><button class="primary">Save properties</button><span>Changes are backed up before writing.</span></div>
        </form><?php endif; ?>
      </section>
    <?php elseif ($tab === 'players'): ?>
      <div class="two-column">
      <?php foreach (['ops'=>'Operators', 'whitelist'=>'Whitelist', 'banned-players'=>'Banned players', 'banned-ips'=>'Banned IP addresses'] as $type=>$label): ?>
        <section class="panel"><h2><?= h($label) ?></h2>
        <?php try { [$listFile,$mode,$entries] = player_source($server,$type); ?>
          <p class="muted"><?= h(basename($listFile)) ?> · <?= count($entries) ?> entries</p>
          <?php if (!$entries): ?><p class="empty">No entries.</p><?php endif; ?>
          <div class="players">
            <?php foreach ($entries as $i=>$entry): ?>
              <div class="player-row"><div><strong><?= h(is_array($entry) ? ($entry['name'] ?? $entry['ip'] ?? 'Unknown') : $entry) ?></strong>
              <?php if (is_array($entry) && isset($entry['uuid'])): ?><small><?= h($entry['uuid']) ?></small><?php endif; ?></div>
              <form method="post" data-confirm="Remove this entry?"><?php hidden_csrf(); hidden_server($selected); ?>
                <input type="hidden" name="action" value="player_remove"><input type="hidden" name="type" value="<?= h($type) ?>">
                <input type="hidden" name="row" value="<?= $i ?>"><input type="hidden" name="hash" value="<?= h(hash('sha256', json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE))) ?>"><button class="subtle danger-text">Remove</button>
              </form></div>
            <?php endforeach; ?>
          </div>
          <form method="post" class="add-player"><?php hidden_csrf(); hidden_server($selected); ?>
            <input type="hidden" name="action" value="player_add"><input type="hidden" name="type" value="<?= h($type) ?>">
            <label><?= $type === 'banned-ips' ? 'IP address' : 'Username' ?><input name="name" required placeholder="<?= $type === 'banned-ips' ? '192.0.2.1' : 'PlayerName' ?>"></label>
            <?php if ($mode === 'json' && $type !== 'banned-ips'): ?><label>Minecraft UUID <input name="uuid" required placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"></label><?php endif; ?>
            <button class="primary">Add entry</button>
          </form>
        <?php } catch (Throwable $e) { ?><div class="alert danger"><?= h($e->getMessage()) ?></div><?php } ?>
        </section>
      <?php endforeach; ?></div>
    <?php elseif ($tab === 'files'): ?>
      <section class="panel"><h2>Additional configuration files</h2><p class="muted">Edit local Bukkit, Spigot and Paper configuration without losing unrelated settings. Only known filenames are permitted; backups are saved before changes.</p>
        <div class="file-links"><?php foreach (editable_files() as $name=>$label): ?>
          <a class="subtle <?= ($_GET['file'] ?? '') === $name ? 'selected' : '' ?>" href="?tab=files&amp;file=<?= h(rawurlencode($name)) ?>"><?= h($label) ?></a>
        <?php endforeach; ?></div>
        <?php
          $available = array_keys(editable_files());
          $fileKey = $_GET['file'] ?? $available[0];
          if (!is_string($fileKey) || !isset(editable_files()[$fileKey])) $fileKey = $available[0];
          try { $body = editable_text($server, $fileKey); $fileError = ''; }
          catch (Throwable $e) { $fileError = $e->getMessage(); $body = ''; }
        ?>
        <?php if ($fileError): ?><div class="alert warning"><?= h($fileError) ?></div><?php else: ?>
        <form method="post"><?php hidden_csrf(); hidden_server($selected); ?>
          <input type="hidden" name="action" value="save_text"><input type="hidden" name="file_key" value="<?= h($fileKey) ?>">
          <label><?= h($fileKey) ?><textarea name="content" class="text-editor" spellcheck="false" rows="22"><?= h($body) ?></textarea></label>
          <div class="actions"><button class="primary">Save configuration</button><span>Review YAML syntax before saving; mcconfig does not parse plugin-specific formats.</span></div>
        </form><?php endif; ?>
      </section>
    <?php elseif ($tab === 'worlds'): ?>
      <section class="panel"><h2>World directories</h2><p class="muted">Detected using level.dat within the server root. Downloads use ZIP archives. Stop Minecraft before exporting or importing to avoid inconsistent world data.</p>
        <?php if (!$worlds): ?><p class="empty">No worlds found. Check your configured server directory.</p><?php endif; ?>
        <div class="world-grid"><?php foreach ($worlds as $world): ?>
          <div class="world"><strong><?= h($world) ?></strong><span><?= ($props['level-name'] ?? 'world') === $world ? 'Selected world' : 'Available world' ?></span>
            <a class="subtle" href="?tab=worlds&amp;action=download_world&amp;world=<?= h(rawurlencode($world)) ?>">Download ZIP</a>
            <?php if (($props['level-name'] ?? 'world') !== $world): ?>
            <form method="post" data-confirm="Select this world? The change takes effect after server restart."><?php hidden_csrf(); hidden_server($selected); ?><input type="hidden" name="action" value="select_world"><input type="hidden" name="world" value="<?= h($world) ?>"><button class="subtle">Select world</button></form>
            <?php endif; ?>
          </div><?php endforeach; ?></div>
      </section>
      <section class="panel spaced"><h2>Import a world ZIP</h2>
        <p class="muted">Imports into a new directory only. Existing worlds are never overwritten. ZIP paths and uncompressed sizes are validated before extraction.</p>
        <form method="post" enctype="multipart/form-data"><?php hidden_csrf(); hidden_server($selected); ?><input type="hidden" name="action" value="world_upload">
          <div class="form-row"><label>New world folder name <input name="world_name" required pattern="[A-Za-z0-9_-]{1,64}" placeholder="MyWorld"></label><label>World archive <input type="file" name="world_zip" accept=".zip,application/zip" required></label></div>
          <label class="check"><input type="checkbox" name="stopped" required> The Minecraft server is stopped.</label>
          <button class="primary">Import world</button><p class="hint">ZIP upload maximum: 512 MiB. Maximum uncompressed world: 2 GiB. Requires the PHP zip extension.</p>
        </form>
      </section>
    <?php elseif ($tab === 'logs'): ?>
      <section class="panel"><div class="panel-title"><div><h2><?= h($logname ?: 'Server log') ?></h2><p class="muted">Latest 350 lines, with a 256 KiB read limit.</p></div><a href="?tab=logs" class="subtle">Refresh</a></div><pre class="log-full"><?= h($log) ?></pre></section>
    <?php elseif ($tab === 'jar'): ?>
      <section class="panel"><h2>Local server JAR</h2>
      <?php $jarName = (string)($server['jar'] ?? ''); $jarPath = $jarName !== '' ? server_file($server, $jarName) : ''; ?>
      <div class="status-line"><span>Configured JAR</span><strong><?= h($jarName ?: 'Not configured') ?></strong></div>
      <div class="status-line"><span>Current file</span><strong><?= $jarPath !== '' && is_file($jarPath) ? h(number_format((filesize($jarPath) ?: 0) / 1048576, 1).' MiB') : 'Not found' ?></strong></div>
      <?php if ($jarPath === ''): ?><div class="alert warning">Add a server with a JAR filename under Settings before uploading.</div>
      <?php else: ?>
        <div class="alert warning">Stop the Minecraft process before replacing its JAR. This application deliberately does not run shell commands, scrape download links or start/stop services.</div>
        <form method="post" enctype="multipart/form-data"><?php hidden_csrf(); hidden_server($selected); ?><input type="hidden" name="action" value="jar_upload">
          <label>Upload replacement server JAR <input type="file" name="jar_file" accept=".jar" required></label>
          <label class="check"><input type="checkbox" name="stopped" required> I confirm that the Minecraft server is stopped.</label>
          <button class="primary">Install uploaded JAR</button>
        </form>
        <p class="hint">A timestamped backup of the old JAR is retained. Upload size is limited by your PHP profile and mcconfig's 512 MiB cap.</p>
      <?php endif; ?>
      </section>
    <?php endif; ?>
  <?php endif; ?>
</main>
<footer class="footer">mcconfig · Modern web edition · Changes to Minecraft files are made only when explicitly saved</footer>
</div></div>
<?php endif; ?>
</body></html>
