<?php
declare(strict_types=1);
/** mcmanager's local worker. No HTTP endpoints, no shell interpolation. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__).'/src/App.php';
require dirname(__DIR__).'/src/Manager.php';
require __DIR__.'/JavaRuntime.php';
require __DIR__.'/WindowsBedrock.php';
require __DIR__.'/WindowsJava.php';
require dirname(__DIR__).'/src/Uploads.php';
require dirname(__DIR__).'/src/Plugins.php';
require dirname(__DIR__).'/src/PluginPackages.php';
require dirname(__DIR__).'/src/ServerJar.php';
require dirname(__DIR__).'/src/Startup.php';
require __DIR__.'/Plugins.php';
require __DIR__.'/PluginPackages.php';
require __DIR__.'/ServerJar.php';
require __DIR__.'/Status.php';
require dirname(__DIR__).'/src/Console.php';
require __DIR__.'/Console.php';
require __DIR__.'/Rcon.php';
require __DIR__.'/BackupRestore.php';
require __DIR__.'/Supervisor.php';
require __DIR__.'/Recovery.php';
require __DIR__.'/Startup.php';
require __DIR__.'/PaperMigration.php';
require __DIR__.'/Logs.php';
require __DIR__.'/DockerAccess.php';

function worker_write(string $file,array $value): void {
    $signed=json_encode(manager_sign($value),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if (!is_dir(dirname($file)) && !mkdir(dirname($file),0700,true)) throw new RuntimeException('Cannot create worker output directory.');
    atomic_write($file,$signed);
}
function worker_touch_heartbeat(): void {
    worker_write(manager_base().'/heartbeat.json',[
        'timestamp'=>time(),'pid'=>getmypid(),'php'=>PHP_VERSION,
        'message'=>'Worker active'
    ]);
}
function worker_progress(int $percent,string $message): void {
    worker_touch_heartbeat();
    $id=$GLOBALS['mcmanager_active_job']??'';
    if (!is_string($id) || !preg_match('/^[a-f0-9]{24}$/D',$id)) return;
    $message=substr(preg_replace('/[\r\n\x00-\x1f]+/',' ',$message)??'',0,300);
    worker_write(manager_base()."/running/$id.json",[
        'id'=>$id,'operation'=>$GLOBALS['mcmanager_active_operation']??'',
        'server_id'=>$GLOBALS['mcmanager_active_server']??'',
        'state'=>'running','percent'=>max(0,min(100,$percent)),
        'message'=>$message,'updated'=>time()]);
}
function worker_log(string $id,string $message): void {
    $file=manager_base().'/manager.log';
    $text=preg_replace('/[\r\n\x00-\x1f]+/',' ', $message) ?? '';
    file_put_contents($file,date('c').' ['.$id.'] '.substr($text,0,500)."\n",FILE_APPEND|LOCK_EX);
    if (filesize($file)>1048576) @rename($file,$file.'.old');
}
function worker_exec(array $command,int $limit=32768,?string $cwd=null,?array $env=null): array {
    if (!function_exists('proc_open')) throw new RuntimeException('PHP CLI proc_open is required.');
    $log=tempnam(manager_base(),'output-');
    if ($log===false) throw new RuntimeException('Cannot allocate command capture.');
    $spec=[0=>['pipe','r'],1=>['file',$log,'w'],2=>['file',$log,'a']];
    $process=proc_open($command,$spec,$pipes,$cwd,$env,['bypass_shell'=>true]);
    if (!is_resource($process)) { @unlink($log);throw new RuntimeException('Cannot launch program.'); }
    fclose($pipes[0]);
    $exit=proc_close($process);
    $output=substr((string)file_get_contents($log),0,$limit);
    @unlink($log);
    return [$exit,trim($output)];
}
function worker_bin(string $name): ?string {
    $known=['docker'=>['/usr/local/bin/docker','/usr/bin/docker','/var/packages/ContainerManager/target/usr/bin/docker'],
        'java'=>['/usr/bin/java','/usr/local/bin/java'],
        'setsid'=>['/usr/bin/setsid','/bin/setsid','/usr/local/bin/setsid'],
        'tar'=>['/bin/tar','/usr/bin/tar']][$name]??[];
    foreach(explode(PATH_SEPARATOR,(string)getenv('PATH')) as $dir) {
        if ($dir!=='') $known[]=$dir.DIRECTORY_SEPARATOR.$name.(PHP_OS_FAMILY==='Windows'?'.exe':'');
    }
    foreach($known as $path) if (is_file($path) && is_executable($path)) return $path;
    return null;
}
function worker_root(): string {
    $path=manager_instances_root();
    if (!is_dir($path) && !mkdir($path,0700,true) && !is_dir($path)) {
        throw new RuntimeException('Configure a writable instances directory (MCMANAGER_INSTANCES_DIR).');
    }
    $resolved=realpath($path);
    if (!$resolved || is_link($path) || !is_writable($resolved)) throw new RuntimeException('Unsafe instances storage.');
    return $resolved;
}
function worker_state(string $id,array $state): array {
    $state['server_id']=$id;$state['checked']=time();
    worker_write(manager_base()."/states/$id.json",$state);
    return $state;
}
function worker_server(string $id): array {
    if (!preg_match('/^[a-f0-9]{12}$/D',$id)) throw new InvalidArgumentException('Invalid registered server.');
    $cfg=app_config();
    if (!isset($cfg['servers'][$id]) || !is_array($cfg['servers'][$id])) throw new RuntimeException('Server not registered.');
    return $cfg['servers'][$id];
}
function worker_meta(string $path): array {
    $file=$path.'/.mcmanager.json';
    if (!is_file($file) || is_link($file)) return [];
    $json=json_decode((string)file_get_contents($file),true);
    return is_array($json)?$json:[];
}
function worker_save_meta(string $path,array $meta): void {
    atomic_write($path.'/.mcmanager.json',json_encode($meta,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
}
function worker_managed(string $id,array $server): array {
    $path=valid_root((string)($server['path']??''));
    $meta=worker_meta($path);
    if (($meta['manager_id']??'')!==$id) throw new RuntimeException('The server is not adopted by mcmanager.');
    return [$path,$meta];
}
function worker_docker_bin(): string {
    $docker=worker_bin('docker');
    if (!$docker) throw new RuntimeException('Docker CLI unavailable. Install Container Manager and explicitly grant the worker access.');
    return $docker;
}
function worker_docker_name(string $id): string {return 'mcmanager-'.$id;}
function worker_container_owned(string $docker,string $id): bool {
    [$exit,$out]=worker_exec([$docker,'inspect','--format','{{index .Config.Labels "ie.arden.mcmanager.id"}}',worker_docker_name($id)]);
    return $exit===0 && $out===$id;
}
function worker_docker_status(string $id): string {
    $docker=worker_bin('docker');
    if (!$docker) return 'docker-unavailable';
    if (!worker_container_owned($docker,$id)) return 'not-created';
    [$code,$out]=worker_exec([$docker,'inspect','--format','{{.State.Status}}',worker_docker_name($id)],512);
    return $code===0?$out:'unknown';
}
function worker_docker_image(array $meta): string {
    if (($meta['edition']??'java')==='bedrock') return 'itzg/minecraft-bedrock-server:latest';
    $required=worker_required_java((string)($meta['version']??''));
    $tag=match($required) {
        8=>'java8',16=>'java16',17=>'java17',21=>'java21',25=>'java25',
        default=>'latest'
    };
    return 'itzg/minecraft-server:'.$tag;
}
function worker_docker_run(string $id,string $path,array $meta,string $image): void {
    $docker=worker_docker_bin();
    $port=(int)$meta['port'];$memory=(int)$meta['memory'];
    // itzg's default UID=1000 could make bind-mounted worlds inaccessible to the package user.
    $uid=fileowner($path);$gid=filegroup($path);
    if (!is_int($uid)||!is_int($gid)||$uid<=0||$gid<=0) {
        throw new RuntimeException('Bind mount needs an unprivileged Unix owner and group.');
    }
    $args=[$docker,'run','-d','--name',worker_docker_name($id),'--restart','unless-stopped',
        '--label','ie.arden.mcmanager.id='.$id,'--memory',$memory.'m',
        '-e','UID='.$uid,'-e','GID='.$gid,'-v',$path.':/data'];
    if ($meta['edition']==='java') {
        $args=array_merge($args,['-p',$port.':25565/tcp','-e','EULA=TRUE',
          '-e','TYPE='.strtoupper((string)$meta['software']),'-e','VERSION='.((string)$meta['version']==='latest'?'LATEST':(string)$meta['version']),
          '-e','MEMORY='.$memory.'M','-e','OVERRIDE_SERVER_PROPERTIES=false']);
    } else {
        $args=array_merge($args,['-p',$port.':19132/udp','-e','EULA=TRUE']);
    }
    $args[]=$image;
    [$exit,$out]=worker_exec($args,2048);
    if ($exit!==0) throw new RuntimeException('Container creation failed: '.substr($out,0,600));
}
function worker_allowed_url(string $url,array $hosts): void {
    $urlParts=parse_url($url);
    if (!is_array($urlParts) || ($urlParts['scheme']??'')!=='https' ||
      !in_array(strtolower((string)($urlParts['host']??'')),$hosts,true) ||
      isset($urlParts['user']) || isset($urlParts['pass']) || isset($urlParts['port'])) {
        throw new RuntimeException('Untrusted download URL.');
    }
}
function worker_json_url(string $url,array $hosts): array {
    worker_allowed_url($url,$hosts);
    $opts=stream_context_create(['http'=>[
       'timeout'=>20,'follow_location'=>0,
       'header'=>"User-Agent: mcmanager/0.4.0 (https://github.com/ardenee/mcconfig)\r\nAccept: application/json\r\n"
    ],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
    $raw=@file_get_contents($url,false,$opts,0,2097152);
    if (!is_string($raw)) throw new RuntimeException('Official version service unavailable.');
    $json=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    if (!is_array($json)) throw new RuntimeException('Invalid upstream metadata.');
    return $json;
}

function worker_latest(string $software,string $version): array {
    if ($software==='paper') {
        $requested=($version!=='' && $version!=='latest')?[$version]:[];
        if (!$requested) {
            $project=worker_json_url('https://fill.papermc.io/v3/projects/paper',['fill.papermc.io']);
            foreach (($project['versions']??[]) as $group) {
                if (is_array($group)) foreach ($group as $v) if (is_string($v)) $requested[]=$v;
            }
            usort($requested, 'version_compare');
            $requested=array_reverse($requested);
        }
        foreach (array_slice($requested,0,40) as $v) {
            if (!preg_match('/^[0-9a-zA-Z._-]{1,48}$/D',$v)) continue;
            $builds=worker_json_url('https://fill.papermc.io/v3/projects/paper/versions/'.rawurlencode($v).'/builds',['fill.papermc.io']);
            foreach($builds as $build) {
                if (!is_array($build) || ($build['channel']??'')!=='STABLE') continue;
                $asset=$build['downloads']['server:default']??[];
                $url=$asset['url']??'';
                $sha=$asset['checksums']['sha256']??'';
                if (!is_string($url)||!is_string($sha)||!preg_match('/^[a-f0-9]{64}$/iD',$sha)) continue;
                worker_allowed_url($url,['fill-data.papermc.io']);
                return ['software'=>'paper','version'=>$v,'build'=>$build['id']??'',
                  'url'=>$url,'hash'=>$sha,'algorithm'=>'sha256'];
            }
        }
        throw new RuntimeException('No stable Paper build available; experimental builds are intentionally excluded.');
    }
    if ($software==='vanilla') {
        $manifest=worker_json_url('https://piston-meta.mojang.com/mc/game/version_manifest_v2.json',['piston-meta.mojang.com']);
        $chosen=($version!=='' && $version!=='latest')?$version:(string)($manifest['latest']['release']??'');
        foreach(($manifest['versions']??[]) as $entry) {
            if (!is_array($entry) || ($entry['id']??'')!==$chosen || ($entry['type']??'')!=='release') continue;
            $meta=worker_json_url((string)$entry['url'],['piston-meta.mojang.com']);
            $server=$meta['downloads']['server']??[];
            $url=$server['url']??'';$sha=$server['sha1']??'';
            if (!is_string($url)||!is_string($sha)||!preg_match('/^[a-f0-9]{40}$/iD',$sha)) continue;
            worker_allowed_url($url,['piston-data.mojang.com']);
            return ['software'=>'vanilla','version'=>$chosen,'build'=>'',
              'url'=>$url,'hash'=>$sha,'algorithm'=>'sha1',
              'java_major'=>$meta['javaVersion']['majorVersion']??null];
        }
        throw new RuntimeException('Release not found in Mojang official manifest.');
    }
    throw new RuntimeException('Native JAR downloads currently supported for Vanilla and Paper only.');
}
function worker_download(array $artifact,string $target): void {
    worker_allowed_url($artifact['url'],['piston-data.mojang.com','fill-data.papermc.io']);
    $tmp=$target.'.download-'.bin2hex(random_bytes(4));
    $context=stream_context_create(['http'=>[
        'timeout'=>75,'follow_location'=>0,
        'header'=>"User-Agent: mcmanager/0.4.0 (https://github.com/ardenee/mcconfig)\r\n"
    ],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
    $in=@fopen($artifact['url'],'rb',false,$context);
    if (!$in) throw new RuntimeException('Upstream download failed.');
    $out=@fopen($tmp,'wb');
    if (!$out) {fclose($in);throw new RuntimeException('Destination not writable.');}
    try {
        $size=0;
        while (!feof($in)) {
            $chunk=fread($in,1048576);
            if ($chunk===false) throw new RuntimeException('Download stream read failed.');
            $size+=strlen($chunk);
            if ($size>268435456) throw new RuntimeException('Download exceeds 256 MiB cap.');
            if ($chunk!=='' && fwrite($out,$chunk)!==strlen($chunk)) throw new RuntimeException('Short download write.');
        }
        fclose($out);$out=null;fclose($in);$in=null;
        $hash=hash_file($artifact['algorithm'],$tmp);
        if (!is_string($hash) || !hash_equals(strtolower($artifact['hash']),strtolower($hash))) {
            throw new RuntimeException('Downloaded file did not match upstream checksum.');
        }
        if (file_get_contents($tmp,false,null,0,4)!=="PK\x03\x04") throw new RuntimeException('Downloaded JAR invalid.');
        if (is_file($target)) backup_file($target);
        if (!rename($tmp,$target)) throw new RuntimeException('Failed to move verified server JAR into place.');
    } finally {
        if (is_resource($out)) fclose($out);
        if (is_resource($in)) fclose($in);
        if (is_file($tmp)) @unlink($tmp);
    }
}
function worker_java_version(string $binary): array {
    [$exit,$raw]=worker_exec([$binary,'-version'],4096);
    $major=0;
    if (preg_match('/version "(\d+)(?:\.(\d+))?/',$raw,$m)) {
        $major=(int)$m[1]===1?(int)($m[2]??0):(int)$m[1];
    } elseif (preg_match('/(?:openjdk|java) (\d+)(?:[.\s]|$)/i',$raw,$m)) $major=(int)$m[1];
    return ['path'=>$binary,'major'=>$major,'raw'=>substr($raw,0,350),'code'=>$exit];
}
function worker_required_java(string $version): int {
    if (preg_match('/^(\d+)\./',$version,$m) && (int)$m[1]>=26) return 25;
    if (preg_match('/^1\.(\d+)(?:\.(\d+))?/',$version,$m)) {
        $minor=(int)$m[1];$patch=(int)($m[2]??0);
        if ($minor>=21||($minor===20&&$patch>=5)) return 21;
        if ($minor>=18) return 17;
        if ($minor===17) return 16;
        return 8;
    }
    return 0;
}
function worker_diagnostics(): array {
    $ramTotal=0;$ramFree=0;
    if (is_file('/proc/meminfo')) {
        foreach(file('/proc/meminfo')?:[] as $line) {
            if (preg_match('/^(MemTotal|MemAvailable):\s+(\d+)/',$line,$m)) {
                if ($m[1]==='MemTotal') $ramTotal=(int)$m[2]*1024;
                else $ramFree=(int)$m[2]*1024;
            }
        }
    }
    $javas=[];$binary=worker_bin('java');
    foreach([17,21,25] as $major) {
        $managed=worker_managed_java($major);
        if ($managed) $javas[]=worker_java_version($managed)+['managed'=>true];
    }
    if ($binary) $javas[]=worker_java_version($binary);
    foreach(['/var/packages/Java8/target/j2sdk-image/bin/java','/usr/local/java/bin/java','/opt/jdk-25/bin/java'] as $j) {
        if (is_file($j)&&is_executable($j)&&$j!==$binary) $javas[]=worker_java_version($j);
    }
    $docker=worker_bin('docker');
    $socket='/var/run/docker.sock';
    $socketStat=@stat($socket);
    $workerUid=function_exists('posix_geteuid')?posix_geteuid():null;
    $workerUser=($workerUid!==null && function_exists('posix_getpwuid'))
        ?(posix_getpwuid($workerUid)['name']??(string)$workerUid):'unknown';
    $owner=is_array($socketStat)?(int)$socketStat['uid']:-1;
    $group=is_array($socketStat)?(int)$socketStat['gid']:-1;
    $ownerName=$owner>=0&&function_exists('posix_getpwuid')?(posix_getpwuid($owner)['name']??(string)$owner):'?';
    $groupName=$group>=0&&function_exists('posix_getgrgid')?(posix_getgrgid($group)['name']??(string)$group):'?';
    $sockDetails=is_array($socketStat)?
        ($socket.' '.$ownerName.':'.$groupName.' '.sprintf('%04o',(int)$socketStat['mode']&0777)):
        'Socket missing: '.$socket;
    $dockerStatus=$docker?'Docker CLI detected; checking daemon':'Docker CLI unavailable';
    $dockerError='';
    if ($docker) {
        [$code,$out]=worker_exec([$docker,'version','--format','{{.Server.Version}}'],1000);
        if ($code===0) $dockerStatus='available: '.$out;
        elseif (stripos($out,'permission denied')!==false) {
            $dockerStatus='Permission denied to Container Manager Docker socket';
            $dockerError=$out;
        } elseif (!is_array($socketStat)) {
            $dockerStatus='Docker daemon socket unavailable';
            $dockerError=$out;
        } else {
            $dockerStatus='Docker CLI cannot connect to its daemon';
            $dockerError=$out;
        }
    }
    return ['php'=>PHP_VERSION,'os'=>PHP_OS_FAMILY,'architecture'=>php_uname('m'),
      'ram_total'=>$ramTotal,'ram_available'=>$ramFree,'free_storage'=>disk_free_space(worker_root()),
      'java'=>$javas,'docker'=>$dockerStatus,'instances_root'=>worker_root(),
      'docker_cli'=>$docker??'not found','docker_socket'=>$sockDetails,
      'docker_worker_user'=>$workerUser,'docker_error'=>substr($dockerError,0,500),
      'container_manager_installed'=>is_dir('/var/packages/ContainerManager'),
      'container_supported'=>$docker && str_starts_with($dockerStatus,'available'),
      'native_supported'=>true,
      'persistent_native_supported'=>PHP_OS_FAMILY==='Linux' && worker_bin('setsid')!==null];
}
function worker_backup(string $id,string $path,string $reason='manual'): string {
    return worker_backup_create($id,$path,$reason);
}

/** Worker owns only explicitly registered Minecraft servers. */
function worker_install(string $id,array $post,array &$native): array {
    $server=worker_server($id);
    if (($server['install_state']??'')!=='pending') throw new RuntimeException('No pending installation for this server.');
    $opts=manager_install_input($post+['eula'=>'1']);
    $health=worker_diagnostics();
    if (!empty($health['free_storage']) && $health['free_storage']<1073741824) {
        throw new RuntimeException('At least 1 GiB free storage is required to install Minecraft.');
    }
    $existing=app_config()['servers']??[];
    $allocated=0;
    foreach($existing as $sid=>$other) {
        if ($sid!==$id && is_array($other) && !in_array($other['install_state']??'ready',['failed'],true))
            $allocated+=(int)($other['memory']??0);
    }
    if (!empty($health['ram_total']) && ($allocated+$opts['memory'])*1048576 > $health['ram_total']*0.80) {
        throw new RuntimeException('Existing configured servers plus this instance would exceed 80% of physical RAM.');
    }
    if (!empty($health['ram_total']) && $opts['memory']*1048576 > $health['ram_total']*0.80) {
        throw new RuntimeException('Requested memory exceeds 80% of physical host RAM.');
    }
    if ($opts['edition']==='bedrock' && $opts['deployment']==='container' &&
        !in_array(strtolower(php_uname('m')),['x86_64','amd64'],true)) {
        throw new RuntimeException('Official Linux Bedrock Dedicated Server requires an x86-64 host.');
    }
    foreach(['edition','deployment','name','software','port','memory'] as $k) {
        if ((string)($server[$k]??'')!==(string)$opts[$k]) throw new RuntimeException('Installation request does not match registered server.');
    }
    worker_progress(10,'Checking NAS compatibility and preparing server storage');
    // Do not create an incomplete world directory if the required Java runtime
    // is absent. Preflight JRE architecture/version before provisioning storage.
    $artifact=null;
    if ($opts['deployment']==='native' && $opts['edition']==='java') {
        $artifact=worker_latest($opts['software'],$opts['version']);
        $min=(int)($artifact['java_major']??worker_required_java((string)$artifact['version']));
        if ($min===0)throw new RuntimeException('Minecraft Java runtime requirement could not be established.');
        try { $java=worker_choose_java((string)$artifact['version']); }
        catch(RuntimeException $missing) {
            if (PHP_OS_FAMILY!=='Windows'||!in_array($min,[17,21,25],true))throw $missing;
            worker_progress(15,'Installing compatible Java '.$min.' automatically');
            worker_install_java($min);
            $java=worker_choose_java((string)$artifact['version']);
        }
        $runtime=worker_java_version($java);
        if ($runtime['major']<$min)
            throw new RuntimeException('No compatible Java runtime installed. Minecraft requires Java '.$min.'.');
    }
    $root=worker_root();
    $path=$root.DIRECTORY_SEPARATOR.$id;
    if (file_exists($path) || is_link($path)) throw new RuntimeException('Server directory already exists.');
    if (!mkdir($path,0700)) throw new RuntimeException('Cannot provision server data directory.');
    $meta=$opts+['manager_id'=>$id,'installed'=>date('c'),'imported'=>false];
    $meta['version']=$opts['version']!==''?$opts['version']:'latest';
    $meta['jar']='server.jar';
    $meta['image']=worker_docker_image($meta);
    try {
        if ($opts['deployment']==='container') {
            if (PHP_OS_FAMILY!=='Linux') throw new RuntimeException('Container installation currently requires a Linux Docker host.');
            $docker=worker_docker_bin();
            if (worker_container_owned($docker,$id)) throw new RuntimeException('A managed container with this ID exists.');
            worker_progress(35,'Downloading supported Minecraft container image');
            [$code,$out]=worker_exec([$docker,'pull',$meta['image']],4096);
            if ($code!==0) throw new RuntimeException('Could not pull supported Minecraft image: '.substr($out,0,600));
            worker_progress(80,'Starting Minecraft container');
            worker_docker_run($id,$path,$meta,$meta['image']);
        } elseif ($opts['edition']==='bedrock' && PHP_OS_FAMILY==='Windows') {
            $installed=windows_bedrock_install($path,$opts+[
                'bedrock_upload_id'=>$post['bedrock_upload_id']??'',
                'bedrock_upload_sha'=>$post['bedrock_upload_sha']??'',
                'bedrock_upload_name'=>$post['bedrock_upload_name']??''
            ]);
            $meta['version']=$installed['version'];
            $meta['sha256']=$installed['sha256'];
            $meta['jar']='';
            $meta['image']='';
        } else {
            if (!is_array($artifact)) throw new RuntimeException('Java server metadata was not resolved.');
            worker_download($artifact,$path.'/server.jar');
            $meta['version']=$artifact['version'];$meta['build']=$artifact['build'];
            file_put_contents($path.'/eula.txt',"# Accepted by administrator through mcmanager\neula=true\n");
            file_put_contents($path.'/server.properties',"# Managed by mcmanager\nserver-port=".$opts['port']."\nmax-players=10\nmotd=".$opts['name']."\n");
        }
        worker_progress(95,'Saving managed server registration');
        worker_save_meta($path,$meta);
        return worker_state($id,['state'=>$opts['deployment']==='container'?worker_docker_status($id):'stopped',
            'version'=>$meta['version'],'build'=>$meta['build']??'','software'=>$meta['software'],
            'path'=>$path,'message'=>'Installed; server is registered for management.']);
    } catch (Throwable $e) {
        // Never remove a container or any existing user data on failure.
        // An incomplete new installation may be examined and retried by the operator.
        worker_log($id,'Installation failed: '.$e->getMessage());
        throw $e;
    }
}
function worker_adopt(string $id,array $provided): array {
    $row=worker_server($id);
    if (($row['path']??'')!==($provided['path']??'')) throw new RuntimeException('Registered server path mismatch.');
    $path=valid_root((string)$row['path']);
    $existing=worker_meta($path);
    if ($existing && ($existing['manager_id']??'')!==$id) throw new RuntimeException('Folder already managed by another server.');
    $edition=$row['edition']??'java';$mode=$row['deployment']??'native';
    if (!in_array($edition,['java','bedrock'],true)||!in_array($mode,['native','container'],true)) {
        throw new RuntimeException('Invalid server edition or deployment.');
    }
    if (!is_file($path.'/server.properties')) throw new RuntimeException('server.properties is required to adopt an existing server.');
    $software=(string)($row['software']??($edition==='bedrock'?'bedrock':'vanilla'));
    if (!in_array($software,['vanilla','paper','spigot','bukkit','fabric','forge','neoforge','purpur','folia','bedrock'],true)) {
        throw new RuntimeException('Invalid server software.');
    }
    $props=properties_read(['path'=>$path]);
    $port=(int)($props['server-port']??($edition==='bedrock'?19132:25565));
    if ($port<1024 || $port>65535) $port=$edition==='bedrock'?19132:25565;
    $meta=$existing?:[
        'manager_id'=>$id,'edition'=>$edition,'deployment'=>$mode,'software'=>$software,
        'name'=>$row['name']??'Minecraft','port'=>$port,'version'=>$row['version']??'unknown',
        'memory'=>(int)($row['memory']??2048),'imported'=>true,
        'jar'=>$row['jar']??'server.jar'
    ];
    if ($mode==='container') {
        $docker=worker_docker_bin();
        $candidate=(string)($row['container_name']??'');
        if ($candidate==='') throw new RuntimeException('Set the existing Docker container name when adopting a container.');
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/D',$candidate)) throw new RuntimeException('Invalid container name.');
        [$code,$details]=worker_exec([$docker,'inspect','--format','{{json .}}',$candidate],16384);
        if ($code!==0) throw new RuntimeException('Container not found.');
        $info=json_decode($details,true);
        if (!is_array($info)) throw new RuntimeException('Cannot verify existing container.');
        $image=(string)($info['Config']['Image']??'');
        $validImage=$edition==='bedrock'
            ? str_starts_with($image,'itzg/minecraft-bedrock-server:')
            : str_starts_with($image,'itzg/minecraft-server:');
        if (!$validImage) throw new RuntimeException('Only supported itzg Minecraft images may be adopted.');
        $matched=false;
        foreach (($info['Mounts']??[]) as $mount) {
            if (($mount['Destination']??'')==='/data' &&
                realpath((string)($mount['Source']??''))===realpath($path)) $matched=true;
        }
        if (!$matched) throw new RuntimeException('Container /data mount does not match this registered server path.');
        $meta['container_name']=$candidate;
        $meta['image']=$image;
    }
    $meta['path']=$path;
    worker_save_meta($path,$meta);
    return worker_state($id,['state'=>$mode==='container'?worker_docker_status_for($id,$meta):'stopped',
        'software'=>$software,'version'=>$meta['version'],
        'message'=>'Existing server adopted. Import does not transfer ownership of its files or container.']);
}
function worker_docker_name_for(string $id,array $meta): string {
    return !empty($meta['imported']) && isset($meta['container_name'])
        ? (string)$meta['container_name'] : worker_docker_name($id);
}
function worker_docker_owned_for(string $id,array $meta): bool {
    $docker=worker_docker_bin();
    $name=worker_docker_name_for($id,$meta);
    if (empty($meta['imported'])) return worker_container_owned($docker,$id);
    [$exit,$details]=worker_exec([$docker,'inspect','--format','{{json .}}',$name],16384);
    if ($exit!==0) return false;
    $json=json_decode($details,true);
    if (!is_array($json)) return false;
    $image=(string)($json['Config']['Image']??'');
    $prefix=($meta['edition']??'java')==='bedrock'?'itzg/minecraft-bedrock-server:':'itzg/minecraft-server:';
    if (!str_starts_with($image,$prefix)) return false;
    $path=(string)($meta['path']??'');
    foreach (($json['Mounts']??[]) as $mount) {
        if (($mount['Destination']??'')==='/data' &&
            realpath((string)($mount['Source']??''))===realpath($path)) return true;
    }
    return false;
}
function worker_docker_status_for(string $id,array $meta): string {
    $docker=worker_bin('docker');
    if (!$docker) return 'docker-unavailable';
    if (!worker_docker_owned_for($id,$meta)) return 'not-verified';
    [$code,$out]=worker_exec([$docker,'inspect','--format','{{.State.Status}}',worker_docker_name_for($id,$meta)],1024);
    return $code===0?$out:'unknown';
}
function worker_process_running(string $id,array &$native): bool {
    if (!isset($native[$id]) || !is_resource($native[$id]['process'])) {
        return PHP_OS_FAMILY==='Linux' && !empty(supervisor_status($id)['active']);
    }
    $info=proc_get_status($native[$id]['process']);
    return is_array($info) && !empty($info['running']);
}
function worker_native_state(string $id,array &$native): string {
    if (worker_process_running($id,$native)) return 'running';
    if (PHP_OS_FAMILY==='Linux' && supervisor_possibly_alive($id)) return 'running-uncertain';
    return 'stopped';
}
function worker_native_spawn(string $id,string $path,array $meta,array &$native): void {
    if (worker_process_running($id,$native)) throw new RuntimeException('Native server is already running.');
    $port=(int)($meta['port']??0);
    if ($port>=1024 && $port<=65535 &&
        (($meta['edition']??'java')==='bedrock'?worker_bedrock_ping($port):worker_java_ping($port))) {
        throw new RuntimeException('A Minecraft server already responds on this local port; refusing a duplicate process.');
    }
    $edition=$meta['edition']??'java';
    $cmd=[];
    if ($edition==='java') {
        $java=worker_choose_java((string)($meta['version']??''));
        $jar=(string)($meta['jar']??'server.jar');
        if (!preg_match('/^[a-zA-Z0-9_.-]+\.jar$/iD',$jar) || !is_file($path.'/'.$jar))
            throw new RuntimeException('Configured server JAR not found.');
        $required=worker_required_java((string)($meta['version']??''));
        $runtime=worker_java_version($java);
        if ($required>0 && $runtime['major']<$required) throw new RuntimeException("Requires Java $required, found Java ".$runtime['major']);
        $heap=(int)($meta['memory']??2048);
        if ($heap<512 || $heap>32768) throw new RuntimeException('Invalid Java heap.');
        $cmd=worker_native_java_command($id,$path,$meta,$java,$jar);
    } else {
        if (PHP_OS_FAMILY==='Windows') {
            $binary=$path.'/bedrock_server.exe';
            if (!is_file($binary)||windows_executable_architecture($binary)!=='x64')
                throw new RuntimeException('Verified native Windows Bedrock executable not found.');
        } elseif (PHP_OS_FAMILY==='Linux') {
            $binary=$path.'/bedrock_server';
            if (!is_file($binary)||!is_executable($binary))
                throw new RuntimeException('Install the official Bedrock Linux server first.');
        } else throw new RuntimeException('Unsupported native Bedrock platform.');
        $cmd=[$binary];
    }
    $logDir=$path.'/logs';
    if (!is_dir($logDir) && !mkdir($logDir,0700,true)) throw new RuntimeException('Cannot create log directory.');
    $log=$logDir.'/mcmanager-console.log';
    $env=null;
    if ($edition==='bedrock' && PHP_OS_FAMILY==='Linux') {
        $env=$_ENV+['LD_LIBRARY_PATH'=>$path];
    }
    $spec=[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']];
    $proc=proc_open($cmd,$spec,$pipes,$path,$env,['bypass_shell'=>true]);
    if (!is_resource($proc)) throw new RuntimeException('Could not start native server.');
    stream_set_blocking($pipes[0],true);
    $native[$id]=['process'=>$proc,'stdin'=>$pipes[0],'path'=>$path];
}
function worker_native_start(string $id,string $path,array $meta,array &$native): void {
    if (PHP_OS_FAMILY!=='Linux') {
        worker_native_spawn($id,$path,$meta,$native);
        return;
    }
    if (supervisor_possibly_alive($id)) {
        throw new RuntimeException('Native server supervisor or Minecraft process already exists.');
    }
    $wanted=supervisor_desired($id);
    supervisor_set_desired($id,true);
    try {supervisor_launch($id);}
    catch(Throwable $e) {
        if (!$wanted) supervisor_set_desired($id,false);
        throw $e;
    }
}
function worker_native_stop(string $id,array &$native): void {
    if (PHP_OS_FAMILY==='Linux' && !isset($native[$id])) {
        supervisor_stop($id);
        return;
    }
    if (!worker_process_running($id,$native)) throw new RuntimeException('This native process is not supervised by the active worker.');
    @fwrite($native[$id]['stdin'],"stop\n");
    @fflush($native[$id]['stdin']);
    $start=time();
    while (worker_process_running($id,$native) && time()-$start<30) usleep(200000);
    if (worker_process_running($id,$native)) {
        // Do not abruptly kill a Minecraft process that may still be saving worlds.
        throw new RuntimeException('Server did not stop within 30 seconds; inspect console before forcing a shutdown.');
    }
    @fclose($native[$id]['stdin']);
    @proc_close($native[$id]['process']);
    unset($native[$id]);
}

function worker_discover(): array {
    $roots=[worker_root()];
    foreach(['/volume1/docker','/volume1/minecraft','/volume2/docker','/volume2/minecraft'] as $root) {
        if (is_dir($root) && is_readable($root)) $roots[]=$root;
    }
    $found=[];$seen=[];
    $walk=function(string $dir,int $depth) use (&$walk,&$found,&$seen):void {
        if ($depth>3 || count($found)>=100 || is_link($dir)) return;
        $real=realpath($dir);
        if (!$real || isset($seen[$real])) return;
        $seen[$real]=true;
        if (is_file($dir.'/server.properties') && !is_link($dir.'/server.properties')) {
            $edition=is_dir($dir.'/worlds')||is_file($dir.'/bedrock_server')?'bedrock':'java';
            $software=is_file($dir.'/paper.jar')||is_file($dir.'/paper.yml')?'paper':'vanilla';
            $found[]=['path'=>$real,'edition'=>$edition,'software'=>$software,
                'name'=>basename($real),'deployment'=>str_contains($real,'docker')?'container':'native'];
            return;
        }
        foreach(scandir($dir)?:[] as $item) {
            if ($item==='.'||$item==='..'||str_starts_with($item,'.')) continue;
            $candidate=$dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($candidate) && !is_link($candidate)) $walk($candidate,$depth+1);
            if (count($found)>=100) return;
        }
    };
    foreach($roots as $root) $walk($root,0);
    return $found;
}
function worker_safe_remove(string $dir,string $id): void {
    $root=worker_root();
    $expected=$root.DIRECTORY_SEPARATOR.$id;
    if (!preg_match('/^[a-f0-9]{12}$/D',$id) ||
        realpath($dir)!==realpath($expected) || $dir===$root) {
        throw new RuntimeException('Refusing to remove data outside an owned installation.');
    }
    $iterator=new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach($iterator as $item) {
        $path=$item->getPathname();
        if ($item->isLink() || $item->isFile()) {
            if (!unlink($path)) throw new RuntimeException('Could not remove a server file.');
        } elseif ($item->isDir()) {
            if (!rmdir($path)) throw new RuntimeException('Could not remove a server folder.');
        }
    }
    if (!rmdir($dir)) throw new RuntimeException('Could not remove server root.');
}
function worker_run_operation(string $op,string $id,array $params,array &$native): array {
    if ($op==='diagnostics') return ['system'=>worker_diagnostics()];
    if ($op==='discover') return ['found'=>worker_discover()];
    if ($op==='install_java') return ['runtime'=>worker_install_java((int)($params['major']??0),$params)];
    if ($op==='install') return worker_install($id,$params,$native);
    if ($op==='install_server_jar') {
        $row=worker_server($id);
        if (in_array(($row['install_state']??'ready'),['pending','failed'],true)) {
            throw new RuntimeException('Complete server provisioning or unregister the failed entry first.');
        }
        if (($row['edition']??'java')!=='java' || ($row['deployment']??'native')!=='native') {
            throw new RuntimeException('The selected destination is not a native Java Minecraft server.');
        }
        if (empty($params['confirmed_stopped'])) {
            throw new RuntimeException('Confirm the destination Minecraft server is stopped before replacing its executable.');
        }
        $root=valid_root((string)($row['path']??''));
        $existing=worker_meta($root);
        if ($existing && ($existing['manager_id']??'')!==$id) {
            throw new RuntimeException('This server directory belongs to another manager registration.');
        }
        if ($existing) {
            [$root,$meta]=worker_managed($id,$row);
            $state=worker_native_state($id,$native);
            $live=worker_live_stats($id,$meta,$state,$native);
            if (!empty($live['external_process']) || $state!=='stopped') {
                throw new RuntimeException('Stop the running Minecraft process before replacing its JAR.');
            }
        } else {
            $meta=[
                'manager_id'=>$id,'edition'=>'java','deployment'=>'native',
                'software'=>$row['software']??'vanilla',
                'name'=>$row['name']??'Minecraft',
                'version'=>$row['version']??'unknown',
                'jar'=>trim((string)($row['jar']??'')) ?: 'server.jar',
                'port'=>(int)($row['port']??(properties_read($row)['server-port']??25565)),
                'memory'=>(int)($row['memory']??2048),
                'imported'=>true
            ];
            $port=(int)$meta['port'];
            if ($port>=1024 && $port<=65535) {
                $socket=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$errstr,0.25);
                if (is_resource($socket)) {
                    fclose($socket);
                    throw new RuntimeException('A process is listening on this Minecraft server port. Stop it before replacing its JAR.');
                }
            }
        }
        return worker_server_jar_install($id,$row,$meta,$params,'stopped');
    }
    $row=worker_server($id);
    if ($op==='adopt') {
        $meta=worker_adopt($id,$params['server']??[]);
        return $meta;
    }
    if (($row['install_state']??'')==='pending') throw new RuntimeException('Server installation is pending.');
    [$path,$meta]=worker_managed($id,$row);
    $mode=$meta['deployment']??'native';
    $state=$mode==='container'
        ? worker_docker_status_for($id,$meta)
        : worker_native_state($id,$native);
    $live=worker_live_stats($id,$meta,$state,$native);
    if (!empty($live['external_process']) && $state==='stopped') $state='running-external';
    if ($op==='status') {
        return worker_state($id,['state'=>$state,'software'=>$meta['software']??'unknown',
            'version'=>$meta['version']??'unknown','build'=>$meta['build']??'',
            'port'=>$meta['port']??null,'memory'=>$meta['memory']??null]+$live);
    }
    if ($op==='switch_to_paper') {
        return worker_switch_to_paper($id,$row,$path,$meta,$state,(string)($params['confirm_name']??''));
    }
    if ($op==='clear_logs') return worker_clear_server_logs($id,$row,$meta,$state);
    if ($op==='configure_startup') return worker_configure_startup($id,$path,$meta,$params);
    if ($op==='console_command') return worker_console_send($id,$row,$meta,(string)($params['command']??''),$native);
    if ($op==='install_server_jar') return worker_server_jar_install($id,$row,$meta,$params,$state);
    if ($op==='install_plugin' || $op==='remove_plugin') {
        return worker_plugin_apply($op,$id,$row,$meta,$params,$state);
    }
    if ($op==='verify_backup') {
        return ['backup'=>worker_backup_verify($id,(string)($params['backup_name']??''),true),
            'message'=>'Backup integrity verified; every file passed SHA-256 validation'];
    }
    if ($op==='restore_backup') {
        if (!in_array($state,['stopped','exited','created'],true)) {
            throw new RuntimeException('Stop the server before restoring; current state: '.$state);
        }
        if (($params['confirm_name']??'')!==($row['name']??'')) {
            throw new RuntimeException('Type the exact server name to confirm restoration.');
        }
        if (($meta['deployment']??'native')==='container' && !worker_docker_owned_for($id,$meta)) {
            throw new RuntimeException('The existing Minecraft container identity could not be verified.');
        }
        return worker_backup_restore($id,$path,(string)($params['backup_name']??''));
    }
    if ($op==='check_updates') {
        if ($mode==='container') {
            return ['software'=>$meta['software']??'unknown','installed'=>$meta['version']??'unknown',
                'message'=>'Container image updates are available via the Update action; restart/recreate is administrator-approved.'];
        }
        $artifact=worker_latest((string)($meta['software']??''),'latest');
        return ['installed'=>$meta['version']??'unknown','available'=>$artifact['version'],
            'build'=>$artifact['build']??'',
            'update_available'=>($artifact['version']!==($meta['version']??'') || ($artifact['build']??'')!==($meta['build']??''))];
    }
    if ($op==='start') {
        if ($mode==='container') {
            if (!worker_docker_owned_for($id,$meta)) throw new RuntimeException('Container identity could not be verified.');
            [$code,$out]=worker_exec([worker_docker_bin(),'start',worker_docker_name_for($id,$meta)]);
            if ($code!==0) throw new RuntimeException('Container start failed: '.$out);
        } else worker_native_start($id,$path,$meta,$native);
        return worker_state($id,['state'=>'starting','message'=>'Start requested']);
    }
    if ($op==='stop') {
        if ($mode==='container') {
            if (!worker_docker_owned_for($id,$meta)) throw new RuntimeException('Container identity could not be verified.');
            [$code,$out]=worker_exec([worker_docker_bin(),'stop','--time','60',worker_docker_name_for($id,$meta)]);
            if ($code!==0) throw new RuntimeException('Container stop failed: '.$out);
        } else worker_native_stop($id,$native);
        return worker_state($id,['state'=>'stopped','message'=>'Stopped gracefully']);
    }
    if ($op==='restart') {
        if ($mode==='container') {
            if (!worker_docker_owned_for($id,$meta)) throw new RuntimeException('Container identity could not be verified.');
            [$code,$out]=worker_exec([worker_docker_bin(),'restart','--time','60',worker_docker_name_for($id,$meta)]);
            if ($code!==0) throw new RuntimeException('Container restart failed: '.$out);
        } else {
            worker_native_stop($id,$native);
            worker_native_start($id,$path,$meta,$native);
        }
        return worker_state($id,['state'=>'starting','message'=>'Restart requested']);
    }
    if ($op==='backup') {
        if ($state!=='stopped' && $state!=='exited' && $state!=='created') throw new RuntimeException('Stop the server before creating a consistent backup.');
        return ['backup'=>worker_backup($id,$path),'message'=>'Server backup completed'];
    }
    if ($op==='update') {
        if (!empty($meta['imported'])) throw new RuntimeException('Automatic updates are restricted to mcmanager-created servers. Import updates must be performed using their original installer.');
        if ($mode==='native') {
            if (($meta['edition']??'java')==='bedrock')
                throw new RuntimeException('Native Bedrock binary updates are not yet implemented. No server files were changed.');
            if ($state!=='stopped') throw new RuntimeException('Stop the native server before updating.');
            $artifact=worker_latest((string)$meta['software'],(string)($params['version']??'latest'));
            $binary=worker_choose_java((string)$artifact['version']);
            $runtime=worker_java_version($binary);
            $minimum=(int)($artifact['java_major']??worker_required_java($artifact['version']));
            if ($minimum===0 || $runtime['major']<$minimum)
                throw new RuntimeException('Server update needs Java '.$minimum.'; installed Java '.$runtime['major']);
            $backup=worker_backup($id,$path,'pre-update');
            $oldJar=$path.'/server.jar';
            if (!is_file($oldJar) || is_link($oldJar)) {
                throw new RuntimeException('A regular existing server JAR is required for managed updates.');
            }
            $rollback=$path.'/.mcmanager-rollback-'.bin2hex(random_bytes(6)).'.jar';
            if (!copy($oldJar,$rollback)) throw new RuntimeException('Could not stage the previous JAR for rollback.');
            $previousMeta=$meta;
            try {
                worker_progress(55,'Installing checksum-verified Minecraft update');
                worker_download($artifact,$oldJar);
                $meta['version']=$artifact['version'];
                $meta['build']=$artifact['build']??'';
                worker_save_meta($path,$meta);
            } catch (Throwable $e) {
                if (!copy($rollback,$oldJar)) {
                    throw new RuntimeException('Update interrupted and automatic JAR rollback failed; recover from '.$backup.'. '.$e->getMessage());
                }
                worker_save_meta($path,$previousMeta);
                throw new RuntimeException('Update failed; previous server JAR and metadata restored. '.$e->getMessage());
            } finally {if (is_file($rollback)) @unlink($rollback);}
            return ['backup'=>$backup,'version'=>$meta['version'],'message'=>'Verified JAR installed; restart server to apply.'];
        }
        if (!worker_docker_owned_for($id,$meta)) throw new RuntimeException('Managed container identity mismatch.');
        if ($state!=='exited' && $state!=='created') throw new RuntimeException('Stop the container before updating; state: '.$state);
        $docker=worker_docker_bin();
        $version=(string)($params['version']??'');
        if ($version!=='' && $version!=='latest' && !preg_match('/^[0-9A-Za-z._-]{1,48}$/D',$version))
            throw new InvalidArgumentException('Invalid target version.');
        $previousMeta=$meta;
        if ($version!=='') $meta['version']=$version;
        $image=worker_docker_image($meta);
        [$oldCode,$oldImageId]=worker_exec([$docker,'inspect','--format','{{.Image}}',worker_docker_name($id)],256);
        if ($oldCode!==0 || !preg_match('/^sha256:[0-9a-f]{64}$/D',$oldImageId)) {
            throw new RuntimeException('Could not identify the existing image for rollback.');
        }
        [$code,$out]=worker_exec([$docker,'pull',$image],2048);
        if ($code!==0) throw new RuntimeException('Container image update failed: '.substr($out,0,500));
        $backup=worker_backup($id,$path,'pre-update');
        [$code,$out]=worker_exec([$docker,'rm',worker_docker_name($id)]);
        if ($code!==0) throw new RuntimeException('Unable to remove stopped container: '.$out);
        try { worker_docker_run($id,$path,$meta,$image); }
        catch (Throwable $err) {
            try {
                // A partially created new container may occupy the name; remove it only with the original manager label.
                if (worker_container_owned($docker,$id)) {
                    worker_exec([$docker,'rm','-f',worker_docker_name($id)]);
                }
                worker_docker_run($id,$path,$previousMeta,$oldImageId);
            }
            catch (Throwable $rollback) {
                throw new RuntimeException('Update and rollback both failed; backup: '.$backup.'. '.$rollback->getMessage());
            }
            throw new RuntimeException('Update failed and previous image was restored; data backup: '.$backup.'. '.$err->getMessage());
        }
        $meta['image']=$image;
        worker_save_meta($path,$meta);
        return ['backup'=>$backup,'version'=>$meta['version'],'message'=>'Container updated and started'];
    }
    if ($op==='uninstall') {
        $confirm=(string)($params['confirm_name']??'');
        if ($confirm!==($row['name']??'')) throw new RuntimeException('Uninstall confirmation did not match.');
        if ($mode==='container') {
            if (!empty($meta['imported'])) {
                if (!worker_docker_owned_for($id,$meta)) throw new RuntimeException('Imported container identity mismatch.');
                // Unregister only. Never remove a container created by another tool.
            } else {
                if (!worker_container_owned(worker_docker_bin(),$id)) throw new RuntimeException('Managed Docker label missing.');
                if ($state!=='exited' && $state!=='created') throw new RuntimeException('Stop the container before uninstalling; state: '.$state);
                [$code,$out]=worker_exec([worker_docker_bin(),'rm',worker_docker_name($id)]);
                if ($code!==0) throw new RuntimeException('Failed to remove managed container: '.$out);
            }
        } elseif ($state!=='stopped') throw new RuntimeException('Stop the native server before uninstalling; state: '.$state);
        $delete=!empty($params['delete_data']);
        if ($delete) {
            if (!empty($meta['imported'])) throw new RuntimeException('Deleting imported server data is forbidden; remove files manually.');
            worker_safe_remove($path,$id);
        } else {
            // Ownership is released so the folder cannot be modified through stale jobs.
            $file=$path.'/.mcmanager.json';
            if (is_file($file)) @unlink($file);
        }
        return ['removed'=>true,'deleted_data'=>$delete,'message'=>$delete?'Managed server and data removed':'Server unregistered; data retained'];
    }
    throw new InvalidArgumentException('Unsupported worker action.');
}

function worker_process_one(array &$native): bool {
    $paths=glob(manager_base().'/queue/*.json')?:[];
    sort($paths,SORT_STRING);
    foreach($paths as $source) {
        $id=basename($source,'.json');
        if (!preg_match('/^[a-f0-9]{24}$/D',$id)) continue;
        $running=manager_base()."/running/$id.json";
        if (!rename($source,$running)) continue;
        $op='unknown';$server='';
        try {
            $raw=file_get_contents($running);
            if (!is_string($raw) || strlen($raw)>8192) throw new RuntimeException('Job request exceeds size limit.');
            $request=manager_unpack($raw);
            if (($request['id']??'')!==$id || !is_string($request['operation']??null) ||
                !is_string($request['server_id']??null) ||
                !is_array($request['params']??null)) throw new RuntimeException('Invalid job record.');
            $created=(int)($request['created']??0);
            if (abs(time()-$created)>3600) throw new RuntimeException('Request expired; resubmit from the web interface.');
            $op=$request['operation'];$server=$request['server_id'];
            $GLOBALS['mcmanager_active_job']=$id;
            $GLOBALS['mcmanager_active_operation']=$op;
            $GLOBALS['mcmanager_active_server']=$server;
            worker_write($running,['id'=>$id,'operation'=>$op,'server_id'=>$server,
                'state'=>'running','message'=>'Worker processing request','started'=>time()]);
            $result=worker_run_operation($op,$server,$request['params'],$native);
            worker_write(manager_base()."/done/$id.json",[
              'id'=>$id,'operation'=>$op,'server_id'=>$server,'state'=>'succeeded',
              'finished'=>time(),'result'=>$result]);
            worker_log($id,$op.' completed');
        } catch (Throwable $e) {
            worker_write(manager_base()."/done/$id.json",[
              'id'=>$id,'operation'=>$op,'server_id'=>$server,'state'=>'failed',
              'finished'=>time(),'message'=>substr($e->getMessage(),0,900)]);
            worker_log($id,$op.' failed: '.$e->getMessage());
        }
        unset($GLOBALS['mcmanager_active_job'],$GLOBALS['mcmanager_active_operation'],
            $GLOBALS['mcmanager_active_server']);
        @unlink($running);
        return true;
    }
    return false;
}
function worker_refresh(array &$native): void {
    $servers=app_config()['servers']??[];
    if (!is_array($servers)) return;
    foreach($servers as $id=>$row) {
        if (!preg_match('/^[a-f0-9]{12}$/D',(string)$id)||!is_array($row)) continue;
        if (($row['install_state']??'')==='pending' ||
            ($row['control_mode']??'')==='dsm-on-demand') continue;
        try {
            [$path,$meta]=worker_managed((string)$id,$row);
            $state=($meta['deployment']??'native')==='container'
                ?worker_docker_status_for((string)$id,$meta)
                :worker_native_state((string)$id,$native);
            $live=worker_live_stats((string)$id,$meta,$state,$native);
            if (!empty($live['external_process']) && $state==='stopped') $state='running-external';
            worker_state((string)$id,['state'=>$state,'software'=>$meta['software']??'unknown',
                'version'=>$meta['version']??'unknown','build'=>$meta['build']??'',
                'port'=>$meta['port']??null,'memory'=>$meta['memory']??null]+$live);
            worker_console_capture((string)$id,$row,$meta,$native);
        } catch (Throwable) {continue;}
    }
}
function worker_main(array $argv): int {
    $mode=$argv[1]??'once';
    if (!in_array($mode,['once','serve','doctor','check-native-stopped'],true)) {
        fwrite(STDERR,"Usage: php agent/worker.php [serve|once|doctor|check-native-stopped]\n");
        return 2;
    }
    manager_initialize();
    if ($mode==='check-native-stopped') {
        $rows=app_config()['servers']??[];
        if (is_array($rows)) foreach($rows as $id=>$row) {
            if (!is_string($id) || !preg_match('/^[a-f0-9]{12}$/D',$id) || !is_array($row)) continue;
            if (($row['deployment']??'native')!=='native') continue;
            if (supervisor_possibly_alive($id)) {
                fwrite(STDERR,"Stop managed native Minecraft server ".($row['name']??$id)." before uninstalling mcmanager.\n");
                return 1;
            }
        }
        return 0;
    }
    $lock=fopen(manager_base().'/worker.lock','c');
    if (!$lock||!flock($lock,LOCK_EX|LOCK_NB)) {
        fwrite(STDERR,"An mcmanager worker is already running.\n");
        return 3;
    }
    $native=[];
    if ($mode==='doctor') {
        echo json_encode(worker_diagnostics(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
        return 0;
    }
    // Inspect incomplete filesystem swaps and mark abandoned operations failed.
    // Never automatically replay install/update/delete/restore jobs.
    worker_reconcile_restore_swaps();
    worker_reconcile_interrupted_jobs();
    $lastRefresh=0;
    $lastRecovery=0;
    $lastUploadCleanup=0;
    $lastDockerProbe=0;
    do {
        try {
            worker_touch_heartbeat();
            if (time()-$lastDockerProbe>=30) {
                worker_publish_docker_access();
                $lastDockerProbe=time();
            }
            if (time()-$lastRefresh>=10) {
                worker_refresh($native);
                $lastRefresh=time();
            }
            if (time()-$lastUploadCleanup>=600) {
                worker_purge_stale_uploads();
                $lastUploadCleanup=time();
            }
            if ($mode==='serve' && time()-$lastRecovery>=30) {
                supervisor_recover_desired();
                $lastRecovery=time();
            }
            if (worker_process_one($native)) continue;
        } catch (Throwable $e) {
            worker_log('agent','Worker error: '.$e->getMessage());
            if ($mode==='once') throw $e;
        }
        if ($mode==='once') break;
        if (is_file(manager_base().'/stop-request')) {
            @unlink(manager_base().'/stop-request');
            // Graceful stop: avoid orphaning processes and world corruption.
            foreach(array_keys($native) as $server) {
                try { if (worker_process_running($server,$native)) worker_native_stop($server,$native); }
                catch (Throwable $e) { worker_log($server,'Shutdown warning: '.$e->getMessage()); }
            }
            break;
        }
        usleep(1000000);
    } while (true);
    worker_write(manager_base().'/heartbeat.json',[
        'timestamp'=>0,'pid'=>getmypid(),'message'=>'Worker stopped']);
    flock($lock,LOCK_UN);fclose($lock);
    return 0;
}
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    try { exit(worker_main($argv)); }
    catch (Throwable $e) { fwrite(STDERR,"mcmanager: ".$e->getMessage()."\n"); exit(1); }
}
