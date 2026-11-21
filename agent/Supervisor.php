<?php
declare(strict_types=1);
/** Independent native server supervision. Invoked by worker, not HTTP. */
function supervisor_folder(string $id): string {
    console_server_id($id);
    $base=manager_base().'/supervisors/'.$id;
    foreach([$base,$base.'/inbox'] as $folder) {
        if (!is_dir($folder) && !mkdir($folder,0700,true) && !is_dir($folder)) {
            throw new RuntimeException('Cannot create private native supervisor directory.');
        }
    }
    return $base;
}
function supervisor_signed_read(string $file): array {
    if (!is_file($file) || is_link($file)) return [];
    try {
        $raw=file_get_contents($file);
        return is_string($raw) && strlen($raw)<=8192?manager_unpack($raw):[];
    } catch(Throwable) {return [];}
}
function supervisor_desired(string $id): bool {
    $state=supervisor_signed_read(supervisor_folder($id).'/desired.json');
    return ($state['server_id']??'')===$id && ($state['running']??false)===true;
}
function supervisor_set_desired(string $id,bool $running): void {
    worker_write(supervisor_folder($id).'/desired.json',['server_id'=>$id,'running'=>$running,'updated'=>time()]);
}
function supervisor_status(string $id): array {
    $state=supervisor_signed_read(supervisor_folder($id).'/heartbeat.json');
    if (($state['server_id']??'')!==$id) return ['state'=>'stopped','active'=>false];
    $age=time()-(int)($state['timestamp']??0);
    $state['active']=abs($age)<12 && ($state['state']??'')==='running';
    return $state;
}
function supervisor_process_exists(int $pid): bool {
    if (PHP_OS_FAMILY!=='Linux' || $pid<2) return false;
    if (function_exists('posix_kill')) return @posix_kill($pid,0);
    return is_dir('/proc/'.$pid);
}
function supervisor_possibly_alive(string $id): bool {
    $status=supervisor_status($id);
    return !empty($status['active']) ||
        supervisor_process_exists((int)($status['pid']??0)) ||
        supervisor_process_exists((int)($status['child_pid']??0));
}
function supervisor_enqueue(string $id,string $operation,string $command=''): void {
    if (!in_array($operation,['command','stop'],true)) throw new InvalidArgumentException('Invalid supervisor operation.');
    if ($operation==='command') $command=console_command_input($command);
    $record=['server_id'=>$id,'operation'=>$operation,'command'=>$command,
        'created'=>time(),'nonce'=>bin2hex(random_bytes(6))];
    atomic_write(supervisor_folder($id).'/inbox/'.bin2hex(random_bytes(12)).'.json',
        json_encode(manager_sign($record),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
}
function supervisor_handle_requests(string $id,array &$native): bool {
    $files=glob(supervisor_folder($id).'/inbox/*.json')?:[];
    sort($files,SORT_STRING);$stopping=false;
    foreach(array_slice($files,0,40) as $file) {
        try {
            $req=supervisor_signed_read($file);
            if (($req['server_id']??'')!==$id || abs(time()-(int)($req['created']??0))>120) continue;
            $stdin=$native[$id]['stdin']??null;
            if (!is_resource($stdin) || !worker_process_running($id,$native)) continue;
            if (($req['operation']??'')==='command') {
                $command=console_command_input((string)($req['command']??''));
                if ($command==='stop') supervisor_set_desired($id,false);
                @fwrite($stdin,$command."\n");@fflush($stdin);
            } elseif (($req['operation']??'')==='stop') {
                @fwrite($stdin,"stop\n");@fflush($stdin);
                $stopping=true;
            }
        } finally {@unlink($file);}
    }
    return $stopping;
}
function supervisor_heartbeat(string $id,array &$native): void {
    $process=$native[$id]['process']??null;
    $s=is_resource($process)?proc_get_status($process):[];
    worker_write(supervisor_folder($id).'/heartbeat.json',[
        'server_id'=>$id,'timestamp'=>time(),'pid'=>getmypid(),
        'child_pid'=>(int)($s['pid']??0),
        'state'=>!empty($s['running'])?'running':'stopped',
        'wanted'=>supervisor_desired($id)
    ]);
}
function supervisor_run(string $id): int {
    if (!supervisor_desired($id)) throw new RuntimeException('Native process is not requested to run.');
    $folder=supervisor_folder($id);
    $lock=fopen($folder.'/lock','c');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Native server supervisor already running.');
    $native=[];$stopping=false;
    try {
        $row=worker_server($id);
        [$path,$meta]=worker_managed($id,$row);
        if (($meta['deployment']??'native')!=='native') throw new RuntimeException('Supervisor requires a native Minecraft server.');
        worker_native_spawn($id,$path,$meta,$native);
        do {
            supervisor_heartbeat($id,$native);
            if (!worker_process_running($id,$native)) break;
            if (supervisor_handle_requests($id,$native)) $stopping=true;
            usleep(350000);
        } while(true);
        if (isset($native[$id])) {
            @fclose($native[$id]['stdin']);
            @proc_close($native[$id]['process']);
        }
        worker_log($id,$stopping?'Native server stopped gracefully':'Native server process exited');
        return 0;
    } finally {
        $empty=[];
        supervisor_heartbeat($id,$empty);
        flock($lock,LOCK_UN);fclose($lock);
    }
}
function supervisor_launch(string $id): void {
    if (PHP_OS_FAMILY!=='Linux') throw new RuntimeException('Persistent native supervision requires Linux.');
    if (supervisor_possibly_alive($id)) throw new RuntimeException('A native Minecraft process may already be running.');
    $setsid=worker_bin('setsid');
    if (!$setsid) throw new RuntimeException('setsid -f is unavailable; cannot detach native supervisor safely.');
    $script=__DIR__.'/native-supervisor.php';
    $log=supervisor_folder($id).'/supervisor.log';
    $spec=[0=>['file','/dev/null','r'],1=>['file',$log,'a'],2=>['file',$log,'a']];
    $proc=proc_open([$setsid,'-f',PHP_BINARY,$script,'run',$id],$spec,$pipes,null,null,['bypass_shell'=>true]);
    if (!is_resource($proc)) throw new RuntimeException('Failed to launch independent supervisor.');
    if (proc_close($proc)!==0) throw new RuntimeException('Supervisor launcher returned an error.');
    for($i=0;$i<24;$i++) {
        usleep(250000);
        if (!empty(supervisor_status($id)['active'])) return;
    }
    throw new RuntimeException('Native supervisor did not start; consult private supervisor.log.');
}
function supervisor_stop(string $id): void {
    supervisor_set_desired($id,false);
    if (!supervisor_possibly_alive($id)) throw new RuntimeException('No supervised native process is running.');
    supervisor_enqueue($id,'stop');
    $end=microtime(true)+45;
    while(microtime(true)<$end) {
        usleep(250000);
        if (!supervisor_possibly_alive($id)) return;
    }
    throw new RuntimeException('Minecraft has not stopped gracefully; no forced termination was performed.');
}
function supervisor_recover_desired(): void {
    if (PHP_OS_FAMILY!=='Linux') return;
    $servers=app_config()['servers']??[];
    if (!is_array($servers)) return;
    foreach($servers as $id=>$row) {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{12}$/D',$id) ||
            !is_array($row) || ($row['install_state']??'ready')==='pending') continue;
        try {
            [$path,$meta]=worker_managed($id,$row);
            if (($meta['deployment']??'native')!=='native' || !supervisor_desired($id) ||
                supervisor_possibly_alive($id)) continue;
            $attempt=supervisor_folder($id).'/last-start-attempt';
            if (is_file($attempt) && time()-(int)filemtime($attempt)<60) continue;
            touch($attempt);
            supervisor_launch($id);
            worker_log($id,'Recovered desired native Minecraft server after worker/NAS restart');
        } catch(Throwable $e) {
            worker_log((string)$id,'Recovery postponed: '.$e->getMessage());
        }
    }
}
