<?php
declare(strict_types=1);
/** Server-scoped log rotation into private recovery storage. Never alters worlds. */
function worker_clear_server_logs(string $id,array $row,array $meta,string $state): array {
    if (!in_array($state,['stopped','exited','created'],true)) {
        throw new RuntimeException('Stop this Minecraft server before clearing logs. The running process may still hold log file handles.');
    }
    if (($meta['deployment']??'native')==='container' && !worker_docker_owned_for($id,$meta)) {
        throw new RuntimeException('Container identity or mounted server data cannot be verified. Log clearing refused.');
    }
    $root=valid_root((string)$row['path']);
    $logsDir=$root.'/logs';
    if (is_link($logsDir)) throw new RuntimeException('Cannot clear a symbolic-linked logs folder.');
    $sources=[];
    $candidate=$root.'/server.log';
    if (is_file($candidate) && !is_link($candidate)) $sources['server.log']=$candidate;
    if (is_dir($logsDir)) {
        $realLogs=realpath($logsDir);
        if ($realLogs===false || dirname($realLogs)!==realpath($root)) {
            throw new RuntimeException('Unsafe Minecraft log directory.');
        }
        foreach(scandir($logsDir)?:[] as $name) {
            if (count($sources)>300) throw new RuntimeException('Too many Minecraft log files for a single operation.');
            if (!preg_match('/^[A-Za-z0-9_.-]{1,110}\.(?:log|log\.gz)$/iD',$name)) continue;
            $file=$logsDir.'/'.$name;
            if (!is_file($file) || is_link($file)) continue;
            $sources['logs/'.$name]=$file;
        }
    }
    if (!$sources) return ['files'=>0,'message'=>'No server log files found to clear.'];
    $safeId=console_server_id($id);
    $backup=manager_base().'/cleared_logs/'.$safeId.'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
    if (!mkdir($backup,0700,true)) throw new RuntimeException('Unable to create private log recovery folder.');
    $moved=[];
    try {
        foreach($sources as $relative=>$path) {
            $dest=$backup.'/'.$relative;
            if (!is_dir(dirname($dest)) && !mkdir(dirname($dest),0700,true) && !is_dir(dirname($dest))) {
                throw new RuntimeException('Could not create log recovery folder.');
            }
            if (!rename($path,$dest)) throw new RuntimeException('Could not move server log: '.$relative);
            $moved[$relative]=$dest;
        }
    } catch(Throwable $e) {
        // Put logs back when possible; never overwrite a log recreated by Minecraft.
        foreach($moved as $relative=>$saved) {
            $original=$sources[$relative];
            if (!file_exists($original) && !is_link($original)) @rename($saved,$original);
        }
        throw $e;
    }
    return ['files'=>count($moved),'archive'=>$backup,
        'message'=>'Cleared '.count($moved).' server log file(s). Copies retained in private recovery storage. Restart or refresh the server to create a new live log.'];
}
