<?php
declare(strict_types=1);
/**
 * mcmanager's unprivileged web/worker job exchange.
 * The PHP web process never executes system commands or accesses Docker.
 * Both processes require private access to MCCONFIG_DATA_DIR.
 */
function manager_base(): string { return data_dir() . '/manager'; }
function manager_instances_root(): string {
    $env=getenv('MCMANAGER_INSTANCES_DIR');
    if (is_string($env) && $env!=='') return rtrim($env,'/\\');
    $data=realpath(data_dir());
    $app=realpath(dirname(__DIR__));
    // Under generic hosting, never create user worlds inside the deployable application tree.
    if ($data && $app && str_starts_with(str_replace('\\','/',$data).'/',str_replace('\\','/',$app).'/')) {
        return dirname($app).DIRECTORY_SEPARATOR.'mcmanager-instances';
    }
    return data_dir().'/instances';
}
function manager_initialize(): void {
    $base = manager_base();
    foreach ([$base, "$base/queue", "$base/done", "$base/running", "$base/states", "$base/backups", "$base/console", "$base/console/subscriptions"] as $path) {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Cannot create private manager queue.');
        }
    }
    $key = "$base/key";
    if (!is_file($key)) {
        $handle = @fopen($key, 'x');
        if ($handle === false && !is_file($key)) throw new RuntimeException('Cannot initialize worker authentication.');
        if ($handle !== false) {
            @chmod($key, 0600);
            fwrite($handle, bin2hex(random_bytes(32)));
            fclose($handle);
        }
    }
}
function manager_key(): string {
    manager_initialize();
    $key = trim((string)file_get_contents(manager_base().'/key'));
    if (!preg_match('/^[a-f0-9]{64}$/D', $key)) throw new RuntimeException('Invalid manager secret.');
    return $key;
}
function manager_sign(array $payload): array {
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    return ['payload'=>$json, 'mac'=>hash_hmac('sha256', $json, manager_key())];
}
function manager_unpack(string $data): array {
    $envelope = json_decode($data, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($envelope) || !is_string($envelope['payload'] ?? null) ||
        !is_string($envelope['mac'] ?? null) ||
        !hash_equals(hash_hmac('sha256', $envelope['payload'], manager_key()), $envelope['mac'])) {
        throw new RuntimeException('Invalid manager request authentication.');
    }
    $payload = json_decode($envelope['payload'], true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) throw new RuntimeException('Invalid manager payload.');
    return $payload;
}
function manager_job_id(string $value): string {
    if (!preg_match('/^[a-f0-9]{24}$/D', $value)) throw new InvalidArgumentException('Invalid job ID.');
    return $value;
}
function manager_enqueue(string $operation, array $params = [], string $serverId = ''): string {
    $allowed = ['diagnostics', 'discover', 'install', 'adopt', 'status', 'check_updates', 'start',
        'stop', 'restart', 'backup', 'verify_backup', 'restore_backup', 'update', 'uninstall', 'install_java', 'console_command', 'install_plugin', 'remove_plugin', 'install_server_jar', 'configure_startup'];
    if (!in_array($operation, $allowed, true)) throw new InvalidArgumentException('Unknown manager operation.');
    if ($serverId !== '' && !preg_match('/^[a-f0-9]{12}$/D', $serverId)) throw new InvalidArgumentException('Invalid server ID.');
    $id = bin2hex(random_bytes(12));
    $payload = ['id'=>$id, 'operation'=>$operation, 'server_id'=>$serverId, 'params'=>$params, 'created'=>time()];
    $record = json_encode(manager_sign($payload), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (strlen($record) > 8192) throw new InvalidArgumentException('Manager request too large.');
    atomic_write(manager_base() . "/queue/$id.json", $record);
    return $id;
}
function manager_result(string $id): ?array {
    manager_job_id($id);
    foreach (['done','running'] as $stage) {
        $file = manager_base() . "/$stage/$id.json";
        if (!is_file($file)) continue;
        $data = file_get_contents($file);
        if ($data === false || strlen($data) > 32768) throw new RuntimeException('Invalid manager result.');
        $result=manager_unpack($data);
        // A worker briefly moves the queued signed request to /running
        // before writing its first progress record. Present that valid
        // transition as running, never as a terminal empty state.
        if ($stage==='running' && !isset($result['state'])) {
            return ['id'=>$id,'operation'=>$result['operation']??'',
                'server_id'=>$result['server_id']??'','state'=>'running',
                'message'=>'Worker accepted the request','percent'=>0];
        }
        return $result;
    }
    $queued = manager_base() . "/queue/$id.json";
    return is_file($queued) ? ['id'=>$id, 'state'=>'queued', 'message'=>'Waiting for worker'] : null;
}
function manager_recent_jobs(int $limit = 20): array {
    manager_initialize();
    $files = glob(manager_base().'/done/*.json') ?: [];
    usort($files, fn($a,$b)=>filemtime($b)<=>filemtime($a));
    $result = [];
    foreach (array_slice($files,0,$limit) as $file) {
        try {
            $value = manager_result(basename($file,'.json'));
            if ($value) $result[] = $value;
        } catch (Throwable) { continue; }
    }
    return $result;
}
function manager_heartbeat(): array {
    manager_initialize();
    $file = manager_base().'/heartbeat.json';
    if (!is_file($file)) return ['online'=>false, 'message'=>'Worker not started'];
    $record = file_get_contents($file);
    try { $status = manager_unpack((string)$record); }
    catch (Throwable) { return ['online'=>false, 'message'=>'Worker status invalid']; }
    $age = time()-(int)($status['timestamp'] ?? 0);
    $status['online'] = abs($age) < 25;
    return $status;
}
function manager_online(): bool {
    $info = manager_heartbeat();
    return abs(time()-(int)($info['timestamp']??0)) < 25;
}
function manager_running_state(string $serverId): array {
    manager_initialize();
    $file=manager_base()."/states/$serverId.json";
    if (!is_file($file)) return ['state'=>'unknown'];
    try { return manager_unpack((string)file_get_contents($file)); }
    catch (Throwable) { return ['state'=>'unknown']; }
}
function manager_form_job(array $post, array $config): string {
    $op = request_string($post,'operation',32);
    $id = request_string($post,'server_id',64);
    $allowed = ['status','check_updates','start','stop','restart','backup','verify_backup','restore_backup','update','uninstall','adopt'];
    if (!in_array($op,$allowed,true)) throw new InvalidArgumentException('Unsupported server operation.');
    if (!isset($config['servers'][$id])) throw new InvalidArgumentException('Server no longer registered.');
    $params=[];
    if ($op==='update') $params['version']=request_string($post,'version',48);
    if ($op==='verify_backup' || $op==='restore_backup') {
        $params['backup_name']=request_string($post,'backup_name',48);
        if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{6}\.zip$/D',$params['backup_name'])) {
            throw new InvalidArgumentException('Select a valid server backup.');
        }
        if ($op==='restore_backup') {
            $params['confirm_name']=request_string($post,'confirm_name',80);
            if ($params['confirm_name']!==($config['servers'][$id]['name']??'')) {
                throw new InvalidArgumentException('Type the exact server name to authorize restoration.');
            }
        }
    }
    if ($op==='uninstall') {
        $params['delete_data']=!empty($post['delete_data']);
        $params['confirm_name']=request_string($post,'confirm_name',80);
        if ($params['confirm_name'] !== ($config['servers'][$id]['name'] ?? '')) {
            throw new InvalidArgumentException('Type the exact server name to confirm uninstall.');
        }
    }
    if ($op==='adopt') $params['server']=$config['servers'][$id];
    return manager_enqueue($op,$params,$id);
}
function manager_backup_files(string $id): array {
    if (!preg_match('/^[a-f0-9]{12}$/D',$id)) return [];
    manager_initialize();
    $dir=manager_base().'/backups/'.$id;
    if (!is_dir($dir)) return [];
    $files=[];
    foreach(glob($dir.'/*.zip')?:[] as $path) {
        $name=basename($path);
        if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{6}\.zip$/D',$name) || is_link($path)) continue;
        $files[]=['name'=>$name,'size'=>(int)filesize($path),'created'=>(int)filemtime($path)];
    }
    usort($files,fn($a,$b)=>strcmp($b['name'],$a['name']));
    return array_slice($files,0,40);
}
function manager_install_input(array $post): array {
    $edition = request_string($post,'edition',12);
    $software = request_string($post,'software',20);
    $deployment = request_string($post,'deployment',12);
    $name = server_name(request_string($post,'name',80));
    $version = request_string($post,'version',48);
    $port = filter_var($post['port'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>1024,'max_range'=>65535]]);
    $memory = filter_var($post['memory'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>512,'max_range'=>32768]]);
    if (!in_array($edition,['java','bedrock'],true) ||
        !in_array($deployment,['native','container'],true) ||
        !in_array($software,$edition==='java'?['vanilla','paper','spigot','bukkit','fabric','forge','neoforge','purpur','folia']:['bedrock'],true) ||
        $port===false || $memory===false) throw new InvalidArgumentException('Invalid server installation options.');
    if ($version!=='' && $version!=='latest' && !preg_match('/^[0-9a-zA-Z][0-9a-zA-Z._-]{0,47}$/D',$version))
        throw new InvalidArgumentException('Invalid Minecraft version.');
    if (empty($post['eula'])) throw new InvalidArgumentException('You must read and accept the Minecraft EULA before installing.');
    if ($deployment==='native' && !($edition==='java' && in_array($software,['vanilla','paper'],true))) {
        throw new InvalidArgumentException('Managed native installation currently supports Vanilla and Paper Java. Use a container for other variants.');
    }
    $options=compact('name','edition','software','deployment','version','port','memory');
    if ($deployment==='native' && $edition==='java') {
        $options=array_merge($options,startup_settings([
            'memory'=>$memory,'xms'=>$post['xms']??256,
            'jvm_args'=>$post['jvm_args']??'',
            'netty_workdir'=>$post['netty_workdir']??'1'
        ],$memory));
    }
    return $options;
}

function manager_cancel_queued_job(string $id): bool {
    manager_job_id($id);
    $file=manager_base().'/queue/'.$id.'.json';
    if (!is_file($file) || is_link($file)) return false;
    try { $record=manager_unpack((string)file_get_contents($file)); }
    catch(Throwable) {return false;}
    if (($record['id']??'')!==$id) return false;
    $cancelled=manager_base().'/queue/.cancelled-'.$id.'-'.bin2hex(random_bytes(3));
    if (!@rename($file,$cancelled)) return false;
    try {
        atomic_write(manager_base().'/done/'.$id.'.json',json_encode(manager_sign([
            'id'=>$id,'server_id'=>$record['server_id']??'',
            'operation'=>$record['operation']??'unknown','state'=>'cancelled',
            'message'=>'Queued job cancelled before execution','finished'=>time()
        ]),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    } finally {@unlink($cancelled);}
    return true;
}
function manager_queued_jobs(): array {
    manager_initialize();
    $jobs=[];
    foreach(array_slice(glob(manager_base().'/queue/*.json')?:[],0,30) as $path) {
        try {
            $record=manager_unpack((string)file_get_contents($path));
            if (is_array($record) && ($record['id']??'')===basename($path,'.json')) $jobs[]=$record;
        } catch(Throwable) {}
    }
    usort($jobs,fn($a,$b)=>($b['created']??0)<=>($a['created']??0));
    return $jobs;
}

function manager_native_resume_requested(string $id): bool {
    if (!preg_match('/^[a-f0-9]{12}$/D',$id)) return false;
    $file=manager_base().'/supervisors/'.$id.'/desired.json';
    if (!is_file($file) || is_link($file)) return false;
    try {
        $status=manager_unpack((string)file_get_contents($file));
        return ($status['server_id']??'')===$id && ($status['running']??false)===true;
    } catch(Throwable) {return false;}
}
