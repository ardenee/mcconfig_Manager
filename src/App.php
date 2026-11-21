<?php
declare(strict_types=1);

/**
 * mcconfig's storage and Minecraft file operations.
 * PHP 8.2+; no commands, database, plugins or runtime dependencies.
 */
function data_dir(): string {
    $d = getenv('MCCONFIG_DATA_DIR');
    if (is_string($d) && $d !== '') return rtrim($d, '/\\');
    $dsm = '/var/packages/mcconfig/var';
    return is_dir($dsm) ? $dsm : dirname(__DIR__) . '/var';
}
function config_file(): string { return data_dir() . '/config.php'; }
function app_config(): array {
    $file = config_file();
    if (!is_file($file)) return [];
    $config = require $file;
    if (!is_array($config)) throw new RuntimeException('Invalid stored configuration.');
    return $config;
}
function save_config(array $config): void {
    $dir = data_dir();
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create private data directory.');
    }
    $data = "<?php\nreturn " . var_export($config, true) . ";\n";
    atomic_write(config_file(), $data);
}
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function request_string(array $values, string $key, int $limit = 2048): string {
    $v = $values[$key] ?? '';
    if (!is_string($v) || strlen($v) > $limit) throw new InvalidArgumentException('Invalid ' . $key . '.');
    return trim($v);
}
function redirect(string $tab = 'overview', string $extra = ''): never {
    header('Location: index.php?tab=' . rawurlencode($tab) . $extra, true, 303);
    exit;
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf'];
}
function check_csrf(): void {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals(csrf_token(), $_POST['csrf'])) {
        throw new RuntimeException('Security token expired; reload the page and retry.');
    }
}
function valid_root(string $path): string {
    $path = trim($path);
    if ($path === '' || !is_dir($path)) throw new InvalidArgumentException('Select an existing Minecraft server directory.');
    $real = realpath($path);
    if ($real === false) throw new RuntimeException('Cannot resolve server directory.');
    $source = realpath(dirname(__DIR__));
    $a = str_replace('\\', '/', rtrim($real, '/\\')) . '/';
    $b = str_replace('\\', '/', (string)$source) . '/';
    if ($source && strncasecmp($a, $b, strlen($b)) === 0) {
        throw new InvalidArgumentException('Minecraft data must be outside the mcconfig application directory.');
    }
    return $real;
}
function server_name(string $name): string {
    $name = trim($name);
    if ($name === '' || strlen($name) > 64 || preg_match('/[<>"\x00-\x1f]/', $name)) {
        throw new InvalidArgumentException('Invalid server name.');
    }
    return $name;
}
function server_from(array $config, string $id): array {
    $servers = $config['servers'] ?? [];
    if (!is_array($servers) || !isset($servers[$id]) || !is_array($servers[$id])) {
        throw new RuntimeException('Select a configured server.');
    }
    $s = $servers[$id];
    $s['path'] = valid_root((string)($s['path'] ?? ''));
    return $s;
}
function server_file(array $server, string $name): string {
    // No user-controlled relative paths, traversal or symlink targets.
    if (!preg_match('/^[A-Za-z0-9._-]+$/D', $name) || $name === '..' || $name === '.') {
        throw new InvalidArgumentException('Invalid file name.');
    }
    $file = $server['path'] . DIRECTORY_SEPARATOR . $name;
    if (is_link($file)) throw new RuntimeException('Symlinked server files are not supported.');
    return $file;
}
function atomic_write(string $target, string $data): void {
    $directory = dirname($target);
    if (!is_dir($directory) || !is_writable($directory)) throw new RuntimeException('Destination directory is not writable: ' . $directory);
    if (is_link($target)) throw new RuntimeException('Refusing to write through symlink.');
    $tmp = tempnam($directory, '.mcconfig-');
    if ($tmp === false) throw new RuntimeException('Cannot allocate temporary file.');
    try {
        if (file_put_contents($tmp, $data, LOCK_EX) !== strlen($data)) {
            throw new RuntimeException('Unable to write the entire file.');
        }
        if (is_file($target)) {
            $mode = fileperms($target);
            if ($mode !== false) @chmod($tmp, $mode & 0777);
        } else @chmod($tmp, 0600);
        if (!@rename($tmp, $target)) {
            // Windows cannot atomically rename over some opened files.
            throw new RuntimeException('Could not replace destination (check file permissions/locks).');
        }
    } finally { if (is_file($tmp)) @unlink($tmp); }
}
function backup_file(string $target): ?string {
    if (!is_file($target)) return null;
    $backup = $target . '.mcconfig-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.bak';
    if (!copy($target, $backup)) throw new RuntimeException('Could not make safety backup; no changes applied.');
    return $backup;
}
function properties_read(array $server): array {
    $file = server_file($server, 'server.properties');
    if (!is_file($file)) return [];
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) throw new RuntimeException('Cannot read server.properties.');
    $out = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*([A-Za-z0-9._-]+)\s*[=:]\s*(.*)$/D', $line, $m)) $out[$m[1]] = $m[2];
    }
    return $out;
}
function properties_save(array $server, array $posted, string $newKey = '', string $newValue = ''): void {
    $file = server_file($server, 'server.properties');
    if (!is_file($file)) throw new RuntimeException('server.properties not found.');
    $original = file_get_contents($file);
    if ($original === false) throw new RuntimeException('Unable to read server.properties.');
    $known = properties_read($server);
    $updates = [];
    foreach ($posted as $key => $value) {
        if (!is_string($key) || !array_key_exists($key, $known) || !is_string($value)) continue;
        if (strlen($value) > 4096 || strpbrk($value, "\r\n") !== false) throw new InvalidArgumentException('Invalid property value: ' . $key);
        $updates[$key] = $value;
    }
    if ($newKey !== '') {
        if (!preg_match('/^[a-zA-Z0-9._-]{1,80}$/D', $newKey)) throw new InvalidArgumentException('Invalid property name.');
        if (array_key_exists($newKey, $known)) throw new InvalidArgumentException('Property already exists.');
        if (strlen($newValue) > 4096 || strpbrk($newValue, "\r\n") !== false) throw new InvalidArgumentException('Invalid new property value.');
        $updates[$newKey] = $newValue;
    }
    if (!$updates) return;
    $newline = str_contains($original, "\r\n") ? "\r\n" : "\n";
    $lines = preg_split('/\r\n|\n|\r/', $original);
    if (!is_array($lines)) throw new RuntimeException('Invalid properties file.');
    // If a key occurs more than once, replace only its final effective occurrence.
    $positions = [];
    foreach ($lines as $i => $line) {
        if (preg_match('/^(\s*)([A-Za-z0-9._-]+)(\s*[=:]\s*)(.*)$/D', $line, $m)) $positions[$m[2]] = [$i, $m[1], $m[3]];
    }
    foreach ($updates as $key => $value) {
        if (isset($positions[$key])) {
            [$i, $indent, $separator] = $positions[$key];
            $lines[$i] = $indent . $key . $separator . $value;
        } else $lines[] = $key . '=' . $value;
    }
    $out = implode($newline, $lines);
    if ($out === $original) return;
    backup_file($file);
    atomic_write($file, $out);
}
function player_source(array $server, string $type): array {
    $map = [
        'ops' => ['ops.json', 'ops.txt'],
        'whitelist' => ['whitelist.json', 'white-list.txt'],
        'banned-players' => ['banned-players.json', 'banned-players.txt'],
        'banned-ips' => ['banned-ips.json', 'banned-ips.txt'],
    ];
    if (!isset($map[$type])) throw new InvalidArgumentException('Unknown player list.');
    [$modern, $legacy] = $map[$type];
    $mode = is_file(server_file($server, $modern)) || !is_file(server_file($server, $legacy)) ? 'json' : 'txt';
    $file = server_file($server, $mode === 'json' ? $modern : $legacy);
    if (!is_file($file)) return [$file, $mode, []];
    $data = file_get_contents($file);
    if ($data === false) throw new RuntimeException('Cannot read player list.');
    if ($mode === 'txt') return [$file, $mode, array_values(array_filter(array_map('trim', preg_split('/\r\n|\n|\r/', $data) ?: []), fn($s) => $s !== ''))];
    try { $values = json_decode($data, true, 512, JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { throw new RuntimeException('Invalid JSON in ' . basename($file) . '; it was not modified.'); }
    if (!is_array($values) || !array_is_list($values)) throw new RuntimeException('Malformed JSON list; no changes made.');
    return [$file, $mode, $values];
}
function player_update(array $server, string $type, string $action, array $input): void {
    [$file, $mode, $entries] = player_source($server, $type);
    if ($action === 'remove') {
        $index = filter_var($input['row'] ?? null, FILTER_VALIDATE_INT);
        if ($index === false || !array_key_exists($index, $entries)) throw new InvalidArgumentException('Player list has changed; reload.');
        $expected = hash('sha256', json_encode($entries[$index], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        if (!hash_equals($expected, request_string($input, 'hash', 128))) throw new RuntimeException('Player list has changed; reload before deleting.');
        array_splice($entries, $index, 1);
    } elseif ($action === 'add') {
        $name = request_string($input, 'name', 80);
        $uuid = strtolower(request_string($input, 'uuid', 64));
        if ($type === 'banned-ips') {
            if (!filter_var($name, FILTER_VALIDATE_IP)) throw new InvalidArgumentException('Enter a valid IPv4 or IPv6 address.');
        } elseif (!preg_match('/^[A-Za-z0-9_]{3,16}$/D', $name)) {
            throw new InvalidArgumentException('Enter a valid Minecraft Java username (3–16 characters).');
        }
        if ($mode === 'json' && $type !== 'banned-ips') {
            $flat = str_replace('-', '', $uuid);
            if (!preg_match('/^[0-9a-f]{32}$/D', $flat)) throw new InvalidArgumentException('Modern JSON lists require a Minecraft player UUID.');
            $uuid = substr($flat,0,8).'-'.substr($flat,8,4).'-'.substr($flat,12,4).'-'.substr($flat,16,4).'-'.substr($flat,20);
        }
        foreach ($entries as $entry) {
            $same = $mode === 'json' ? ($type === 'banned-ips' ? (($entry['ip'] ?? '') === $name) : (($entry['uuid'] ?? '') === $uuid)) : strcasecmp((string)$entry, $name) === 0;
            if ($same) throw new InvalidArgumentException('Entry already exists.');
        }
        if ($mode === 'txt') $entries[] = $name;
        elseif ($type === 'ops') $entries[] = ['uuid'=>$uuid, 'name'=>$name, 'level'=>4, 'bypassesPlayerLimit'=>false];
        elseif ($type === 'whitelist') $entries[] = ['uuid'=>$uuid, 'name'=>$name];
        else {
            $entry = ['created'=>date('Y-m-d H:i:s O'), 'source'=>'mcconfig', 'expires'=>'forever', 'reason'=>'Added by administrator'];
            if ($type === 'banned-ips') $entries[] = ['ip'=>$name] + $entry;
            else $entries[] = ['uuid'=>$uuid, 'name'=>$name] + $entry;
        }
    } else throw new InvalidArgumentException('Invalid player action.');
    backup_file($file);
    atomic_write($file, $mode === 'txt' ? implode("\n", $entries) . "\n" : json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
function list_worlds(array $server): array {
    $dir = $server['path'];
    $found = [];
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..' || is_link($dir . DIRECTORY_SEPARATOR . $item)) continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && is_file($path . DIRECTORY_SEPARATOR . 'level.dat')) $found[] = $item;
    }
    natcasesort($found);
    return array_values($found);
}
function log_text(array $server): array {
    $paths = ['logs/latest.log', 'server.log'];
    foreach ($paths as $relative) {
        $path = $server['path'];
        foreach (explode('/', $relative) as $part) {
            $path .= DIRECTORY_SEPARATOR . $part;
            if (is_link($path)) continue 2;
        }
        if (!is_file($path)) continue;
        $fp = fopen($path, 'rb');
        if (!$fp) break;
        try {
            $size = filesize($path) ?: 0;
            fseek($fp, max(0, $size - 262144));
            $data = stream_get_contents($fp, 262144) ?: '';
        } finally { fclose($fp); }
        if ($size > 262144) $data = substr($data, strpos($data, "\n") + 1);
        $lines = preg_split('/\r\n|\n|\r/', $data) ?: [];
        return [$relative, implode("\n", array_slice($lines, -350))];
    }
    return ['', 'No log found (logs/latest.log or server.log).'];
}
function jar_upload(array $server, array $upload, bool $confirmed): void {
    if (!$confirmed) throw new InvalidArgumentException('Confirm the Minecraft server is stopped first.');
    $name = (string)($server['jar'] ?? '');
    if (!preg_match('/^[A-Za-z0-9._-]+\.jar$/iD', $name)) throw new RuntimeException('Configure a JAR filename for this server first.');
    $target = server_file($server, $name);
    if (($upload['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($upload['tmp_name'] ?? ''))) {
        throw new RuntimeException('JAR upload failed. Check PHP upload_max_filesize and post_max_size.');
    }
    if (($upload['size'] ?? 0) < 4 || ($upload['size'] ?? 0) > 536870912) throw new InvalidArgumentException('Invalid JAR size (maximum 512 MiB).');
    $fp = fopen((string)$upload['tmp_name'], 'rb');
    if (!$fp) throw new RuntimeException('Cannot inspect upload.');
    $signature = fread($fp, 4);
    fclose($fp);
    if (!in_array($signature, ["PK\x03\x04", "PK\x05\x06"], true)) throw new InvalidArgumentException('Uploaded file is not a ZIP/JAR archive.');
    backup_file($target);
    if (!move_uploaded_file((string)$upload['tmp_name'], $target)) throw new RuntimeException('Could not install JAR; check write permissions.');
}

function editable_files(): array {
    return [
        'bukkit.yml'=>'Bukkit configuration', 'spigot.yml'=>'Spigot configuration',
        'paper.yml'=>'Paper configuration (legacy)', 'permissions.yml'=>'Permissions',
        'commands.yml'=>'Commands', 'help.yml'=>'Help',
        'eula.txt'=>'Minecraft EULA',
    ];
}
function editable_text(array $server, string $name): string {
    if (!isset(editable_files()[$name])) throw new InvalidArgumentException('Configuration file is not allowed.');
    $file = server_file($server, $name);
    if (!is_file($file)) throw new RuntimeException('This configuration file does not exist yet.');
    if (filesize($file) > 1048576) throw new RuntimeException('File exceeds the 1 MiB editor limit.');
    $content = file_get_contents($file);
    if ($content === false) throw new RuntimeException('Cannot read configuration file.');
    return $content;
}
function save_editable_text(array $server, string $name, string $content): void {
    if (strlen($content) > 1048576 || str_contains($content, "\0")) {
        throw new InvalidArgumentException('Invalid file content (maximum 1 MiB).');
    }
    $existing = editable_text($server, $name);
    if ($existing === $content) return;
    $file = server_file($server, $name);
    backup_file($file);
    atomic_write($file, $content);
}
/** Creates a bounded snapshot and returns a temporary ZIP path. Caller unlinks after response. */
function export_world(array $server, string $name): string {
    if (!class_exists('ZipArchive')) throw new RuntimeException('Enable the PHP zip extension for world downloads.');
    if (!in_array($name, list_worlds($server), true)) throw new InvalidArgumentException('World not found.');
    $root = $server['path'] . DIRECTORY_SEPARATOR . $name;
    $dir = data_dir();
    if (!is_writable($dir)) throw new RuntimeException('Private temporary directory is not writable.');
    $file = tempnam($dir, 'mcconfig-world-');
    if ($file === false) throw new RuntimeException('Could not allocate temporary archive.');
    $zip = new ZipArchive();
    try {
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Cannot open ZIP archive.');
        $zip->addEmptyDir($name);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $files = 0; $total = 0;
        foreach ($iterator as $entry) {
            if ($entry->isLink()) throw new RuntimeException('World contains a symlink; refusing to export.');
            $relative = substr($entry->getPathname(), strlen($root) + 1);
            $relative = str_replace('\\', '/', $relative);
            if ($entry->isDir()) {
                $zip->addEmptyDir($name . '/' . $relative);
            } elseif ($entry->isFile()) {
                $files++;
                $total += $entry->getSize();
                if ($files > 100000 || $total > 2147483648) throw new RuntimeException('World exceeds the export safety limit (2 GiB / 100,000 files).');
                if (!$zip->addFile($entry->getPathname(), $name . '/' . $relative)) throw new RuntimeException('Cannot add world file to ZIP.');
            }
        }
        if (!$zip->close()) throw new RuntimeException('Failed to finalize world archive.');
        return $file;
    } catch (Throwable $e) {
        try { $zip->close(); } catch (Throwable) {}
        @unlink($file);
        throw $e;
    }
}
function world_archive_name(string $name): string {
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $name)) {
        throw new InvalidArgumentException('World name must use letters, numbers, underscores or hyphens.');
    }
    return $name;
}
/** Validate an uploaded ZIP before creating the destination, and extract with path confinement. */
function import_world_archive(array $server, string $name, string $zipPath): void {
    if (!class_exists('ZipArchive')) throw new RuntimeException('Enable the PHP zip extension for world uploads.');
    $name = world_archive_name($name);
    $target = $server['path'] . DIRECTORY_SEPARATOR . $name;
    if (file_exists($target) || is_link($target)) throw new RuntimeException('World already exists; refusing to overwrite.');
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) throw new RuntimeException('Invalid ZIP archive.');
    $prefix = '';
    $entries = [];
    try {
        if ($zip->numFiles > 100000) throw new RuntimeException('Archive contains too many files.');
        $total = 0; $roots = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) throw new RuntimeException('Unable to inspect ZIP entry.');
            $entry = $stat['name'];
            if (str_contains($entry, '\\') || str_contains($entry, "\0")
                || str_starts_with($entry, '/') || preg_match('/^[A-Za-z]:/', $entry)
                || preg_match('#(^|/)\.\.?(/|$)#', $entry)) {
                throw new RuntimeException('ZIP contains an unsafe path.');
            }
            $parts = explode('/', $entry);
            $roots[$parts[0]] = true;
            $total += $stat['size'];
            if ($total > 2147483648) throw new RuntimeException('ZIP content exceeds the 2 GiB limit.');
            if ($zip->getExternalAttributesIndex($i, $opsys, $attrs)) {
                if ($opsys === ZipArchive::OPSYS_UNIX && (($attrs >> 16) & 0170000) === 0120000) {
                    throw new RuntimeException('Symlinks in ZIP files are unsupported.');
                }
            }
            $entries[] = [$i, $entry];
        }
        if (count($roots) === 1) {
            $root = array_key_first($roots);
            if ($root !== '' && $root !== 'level.dat') {
                foreach ($entries as [, $entry]) {
                    if ($entry !== $root . '/' && !str_starts_with($entry, $root . '/')) { $root = ''; break; }
                }
                if ($root !== '') $prefix = $root . '/';
            }
        }
        $hasLevel = false;
        foreach ($entries as [, $entry]) {
            $relative = $prefix && str_starts_with($entry, $prefix) ? substr($entry, strlen($prefix)) : $entry;
            if ($relative === 'level.dat') $hasLevel = true;
        }
        if (!$hasLevel) throw new RuntimeException('ZIP does not contain a world level.dat file.');
        if (!mkdir($target, 0750)) throw new RuntimeException('Unable to create world directory.');
        try {
            foreach ($entries as [$index, $entry]) {
                $relative = $prefix && str_starts_with($entry, $prefix) ? substr($entry, strlen($prefix)) : $entry;
                if ($relative === '') continue;
                $destination = $target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, rtrim($relative, '/'));
                if (str_ends_with($entry, '/')) {
                    if (!is_dir($destination) && !mkdir($destination, 0750, true) && !is_dir($destination)) {
                        throw new RuntimeException('Could not create world directory.');
                    }
                    continue;
                }
                $parent = dirname($destination);
                if (!is_dir($parent) && !mkdir($parent, 0750, true) && !is_dir($parent)) {
                    throw new RuntimeException('Could not prepare world directory.');
                }
                $input = $zip->getStream($entry);
                if (!$input) throw new RuntimeException('Cannot open world ZIP entry.');
                $output = fopen($destination, 'xb');
                if (!$output) { fclose($input); throw new RuntimeException('Cannot create extracted world file.'); }
                try { if (stream_copy_to_stream($input, $output) === false) throw new RuntimeException('Failed to extract world file.'); }
                finally { fclose($input); fclose($output); }
            }
        } catch (Throwable $e) {
            delete_fixture_tree($target);
            throw $e;
        }
    } finally { $zip->close(); }
}
function delete_fixture_tree(string $directory): void {
    // Only called for an exclusively-created, empty-on-entry destination after failed import.
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path) && !is_link($path)) delete_fixture_tree($path);
        else @unlink($path);
    }
    @rmdir($directory);
}
