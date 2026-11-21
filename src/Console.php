<?php
declare(strict_types=1);
/** Shared web/worker console validation and signed output snapshots. */
function console_server_id(string $id): string {
    if (!preg_match('/^[a-f0-9]{12}$/D', $id)) throw new InvalidArgumentException('Invalid Minecraft server ID.');
    return $id;
}
function console_command_input(string $input): string {
    // Reject control characters before trimming; otherwise leading NUL/newline
    // could be silently removed and an unsafe input accepted.
    if (preg_match('/[\x00-\x1f\x7f]/', $input)) {
        throw new InvalidArgumentException('Minecraft commands must be a single text line.');
    }
    $command = trim($input);
    if (str_starts_with($command, '/')) $command = substr($command, 1);
    if ($command === '' || strlen($command) > 400 ||
        preg_match('/[\x00-\x1f\x7f]/', $command) ||
        preg_match('//u', $command) !== 1) {
        throw new InvalidArgumentException('Enter one valid Minecraft command (maximum 400 bytes, no control characters).');
    }
    return $command;
}
function console_subscribe(string $id): void {
    console_server_id($id);
    manager_initialize();
    $folder=manager_base().'/console/subscriptions';
    if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
        throw new RuntimeException('Cannot activate console streaming.');
    }
    // The worker will only run relatively expensive Docker log queries for recently viewed consoles.
    $file=$folder.'/'.$id;
    if (file_put_contents($file, (string)time(), LOCK_EX) === false) throw new RuntimeException('Cannot activate console.');
}
function console_snapshot(string $id): array {
    console_server_id($id);
    manager_initialize();
    $path=manager_base().'/console/'.$id.'.json';
    if (!is_file($path)) return ['output'=>'', 'source'=>'Awaiting server output', 'updated'=>0];
    $data=file_get_contents($path);
    if (!is_string($data) || strlen($data)>60000) return ['output'=>'','source'=>'Invalid output snapshot','updated'=>0];
    try {
        $record=manager_unpack($data);
        if (($record['server_id']??'')!==$id) throw new RuntimeException('Console output does not match server.');
        return $record;
    } catch (Throwable) {
        return ['output'=>'','source'=>'Invalid output signature','updated'=>0];
    }
}
function console_recent_job(string $id, string $job): ?array {
    if ($job==='' || !preg_match('/^[a-f0-9]{24}$/D',$job)) return null;
    $status=manager_result($job);
    if (!$status || ($status['server_id']??$id)!==$id ||
        ($status['operation']??'console_command')!=='console_command') return null;
    return [
        'state'=>(string)($status['state']??'unknown'),
        'message'=>(string)($status['message']??$status['result']['message']??''),
        'result'=>(string)($status['result']['response']??''),
    ];
}
