<?php
declare(strict_types=1);
/** Build native Java argv without a shell and choose a private Netty JNI dir. */
function startup_is_noexec(string $directory): bool {
    if (PHP_OS_FAMILY!=='Linux' || !is_file('/proc/mounts')) return false;
    $real=realpath($directory);
    if (!is_string($real)) return false;
    $lines=@file('/proc/mounts',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return false;
    $longest=-1;$noexec=false;
    foreach($lines as $line) {
        $parts=preg_split('/\s+/',$line);
        if (!is_array($parts)||count($parts)<4)continue;
        $mount=str_replace(['\040','\011','\134'],[' ',"\t",'\\'],$parts[1]);
        $prefix=rtrim($mount,'/').'/';
        if ($mount==='/' || $real===$mount || str_starts_with($real.'/',$prefix)) {
            $length=strlen($mount);
            if ($length>$longest) {
                $longest=$length;
                $noexec=in_array('noexec',explode(',',$parts[3]),true);
            }
        }
    }
    return $noexec;
}
function startup_native_workdir(string $path): string {
    $root=realpath($path);
    if ($root===false) throw new RuntimeException('Cannot resolve the Minecraft server root for Netty.');
    $dir=$root.'/.mcmanager-netty-native';
    if (is_link($dir)) throw new RuntimeException('Netty native directory must not be a symlink.');
    if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create private Netty native library directory.');
    }
    if (str_replace('\\','/',(string)realpath($dir))!==str_replace('\\','/',$dir) || !is_writable($dir)) {
        throw new RuntimeException('Netty native library directory is not writable or is unsafe.');
    }
    return $dir;
}
function worker_native_java_command(string $id,string $path,array $meta,string $java,string $jar): array {
    $settings=startup_settings([
        'xms'=>$meta['xms']??256,
        'memory'=>$meta['memory']??2048,
        'jvm_args'=>$meta['jvm_args']??'',
        'native_access'=>!empty($meta['native_access'])?'1':'0',
        'netty_workdir'=>(!array_key_exists('netty_workdir',$meta)||$meta['netty_workdir'])?'1':'0'
    ]);
    $argv=[$java,'-Xms'.$settings['xms'].'M','-Xmx'.$settings['memory'].'M'];
    if ($settings['netty_workdir']) {
        $dir=startup_native_workdir($path);
        if (startup_is_noexec($dir)) {
            worker_log($id,'Netty native dir is on a noexec volume: '.$dir.'. Native epoll cannot be loaded there. Set a suitable executable filesystem for the server storage.');
        } else {
            $argv[]='-Dio.netty.native.workdir='.$dir;
        }
    }
    if ($settings['native_access'] &&
        !in_array('--enable-native-access=ALL-UNNAMED',startup_arguments($settings['jvm_args']),true)) {
        $argv[]='--enable-native-access=ALL-UNNAMED';
    }
    foreach(startup_arguments($settings['jvm_args']) as $flag) $argv[]=$flag;
    array_push($argv,'-jar',$jar,'nogui');
    return $argv;
}
function worker_configure_startup(string $id,string $path,array $meta,array $params): array {
    if (($meta['edition']??'java')!=='java' || ($meta['deployment']??'native')!=='native') {
        throw new RuntimeException('Native Java launch settings are available only for native Java Minecraft servers.');
    }
    $settings=startup_settings($params,(int)($meta['memory']??2048));
    // Keep the same host-wide allocation constraint as new server installs.
    $health=worker_diagnostics();
    $others=app_config()['servers']??[];
    $allocated=0;
    if (is_array($others))foreach($others as $serverId=>$row) {
        if ($serverId!==$id && is_array($row) &&
            !in_array(($row['install_state']??'ready'),['failed'],true)) {
            $allocated+=(int)($row['memory']??0);
        }
    }
    if (!empty($health['ram_total']) &&
        ($allocated+$settings['memory'])*1048576>$health['ram_total']*.8) {
        throw new RuntimeException('Configured Minecraft servers would exceed 80% of local host physical memory.');
    }
    foreach($settings as $key=>$value)$meta[$key]=$value;
    worker_save_meta($path,$meta);
    $settings['message']='Startup settings saved for '.$id.'. The next native Minecraft process start uses these values. An already-running server is not restarted.';
    return $settings;
}
