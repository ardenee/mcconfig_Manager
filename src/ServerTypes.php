<?php
declare(strict_types=1);

/** Server edition and storage adapters. Containers are managed by host bind mounts,
 * never by mounting /var/run/docker.sock or shelling out from a web process. */
function server_edition(array $server): string {
    return (($server['edition'] ?? 'java') === 'bedrock') ? 'bedrock' : 'java';
}
function is_bedrock(array $server): bool { return server_edition($server) === 'bedrock'; }
function is_container(array $server): bool { return ($server['deployment'] ?? 'native') === 'container'; }
function world_root(array $server): string {
    if (is_bedrock($server)) {
        $explicit = trim((string)($server['worlds_path'] ?? ''));
        return valid_root($explicit !== '' ? $explicit : $server['path'] . DIRECTORY_SEPARATOR . 'worlds');
    }
    return $server['path'];
}
function bedrock_lists(): array {
    return ['bedrock_allowlist' => 'allowlist.json', 'bedrock_permissions' => 'permissions.json'];
}
function bedrock_source(array $server, string $type): array {
    if (!is_bedrock($server) || !isset(bedrock_lists()[$type])) {
        throw new InvalidArgumentException('Unsupported Bedrock player list.');
    }
    $file = server_file($server, bedrock_lists()[$type]);
    if (!is_file($file)) return [$file, 'json', []];
    $data = file_get_contents($file);
    if ($data === false || strlen($data) > 4194304) throw new RuntimeException('Bedrock player list is unreadable or too large.');
    try { $rows = json_decode($data, true, 512, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new RuntimeException('Invalid Bedrock JSON; file was not modified.'); }
    if (!is_array($rows) || !array_is_list($rows)) {
        throw new RuntimeException('Bedrock list must be a JSON array.');
    }
    foreach ($rows as $row) {
        if (!is_array($row)) throw new RuntimeException('Malformed entry in Bedrock JSON; no changes made.');
    }
    return [$file, 'json', $rows];
}
function bedrock_update(array $server, string $type, string $action, array $input): void {
    [$file, , $rows] = bedrock_source($server, $type);
    if ($action === 'remove') {
        $row = filter_var($input['row'] ?? null, FILTER_VALIDATE_INT);
        if ($row === false || !array_key_exists($row, $rows)) throw new InvalidArgumentException('Entry has changed; reload.');
        $expected = hash('sha256', json_encode($rows[$row], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        if (!hash_equals($expected, request_string($input, 'hash', 128))) {
            throw new RuntimeException('Player list changed; reload before deleting.');
        }
        array_splice($rows, $row, 1);
    } elseif ($action === 'add') {
        $xuid = request_string($input, 'xuid', 32);
        if ($xuid !== '' && !preg_match('/^[0-9]{15,20}$/D', $xuid)) {
            throw new InvalidArgumentException('Bedrock XUID must be 15–20 digits.');
        }
        if ($type === 'bedrock_permissions') {
            if ($xuid === '') throw new InvalidArgumentException('Bedrock permissions require the player XUID.');
            $role = request_string($input, 'permission', 12);
            if (!in_array($role, ['operator', 'member', 'visitor'], true)) {
                throw new InvalidArgumentException('Invalid Bedrock permission.');
            }
            foreach ($rows as $entry) {
                if (($entry['xuid'] ?? '') === $xuid) throw new InvalidArgumentException('XUID already exists.');
            }
            $rows[] = ['permission' => $role, 'xuid' => $xuid];
        } else {
            $name = request_string($input, 'name', 64);
            if ($name === '' || strlen($name) > 64 || preg_match('/[<>\x00-\x1f]/', $name)) {
                throw new InvalidArgumentException('Enter a valid Xbox gamertag.');
            }
            foreach ($rows as $entry) {
                if (strcasecmp((string)($entry['name'] ?? ''), $name) === 0 ||
                    ($xuid !== '' && ($entry['xuid'] ?? '') === $xuid)) {
                    throw new InvalidArgumentException('Gamertag or XUID already exists.');
                }
            }
            $new = ['name' => $name, 'ignoresPlayerLimit' => false];
            if ($xuid !== '') $new['xuid'] = $xuid;
            $rows[] = $new;
        }
    } else throw new InvalidArgumentException('Invalid Bedrock list operation.');
    $contents = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    backup_file($file);
    atomic_write($file, $contents);
}
