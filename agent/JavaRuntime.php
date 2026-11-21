<?php
declare(strict_types=1);
/** Side-by-side Eclipse Temurin Java runtimes, confined to mcmanager-owned storage.
 * No package manager commands and no modifications to system Java.
 * Source: api.adoptium.net, checksum verified before extraction.
 */
function worker_java_asset(int $major): array {
    if (!in_array($major,[17,21,25],true)) throw new InvalidArgumentException('Managed Java supports LTS 17, 21 and 25.');
    if (PHP_OS_FAMILY!=='Linux') throw new RuntimeException('Managed Temurin JRE installation currently requires Linux.');
    $platform=strtolower(php_uname('m'));
    $arch=match($platform) {
        'x86_64','amd64'=>'x64',
        'aarch64','arm64'=>'aarch64',
        default=>throw new RuntimeException('No compatible Temurin JRE for this CPU architecture.')
    };
    $url="https://api.adoptium.net/v3/assets/latest/$major/hotspot?architecture=$arch&image_type=jre&os=linux&vendor=eclipse";
    $assets=worker_json_url($url,['api.adoptium.net']);
    if (!isset($assets[0]) || !is_array($assets[0])) throw new RuntimeException('Adoptium does not list a compatible release.');
    $package=$assets[0]['binary']['package']??[];
    $link=$package['link']??'';
    $sha=$package['checksum']??'';
    if (!is_string($link)||!is_string($sha)||!preg_match('/^[0-9a-f]{64}$/iD',$sha)) {
        throw new RuntimeException('Adoptium download metadata is incomplete.');
    }
    worker_allowed_url($link,['github.com']);
    $name=(string)($package['name']??'');
    if (!str_ends_with($name,'.tar.gz')) throw new RuntimeException('Unexpected Java archive format.');
    return ['major'=>$major,'architecture'=>$arch,'url'=>$link,'sha256'=>strtolower($sha),
        'release'=>(string)($assets[0]['release_name']??'unknown')];
}
function worker_download_java_archive(string $url,string $expected,string $target): void {
    $allowed=['github.com','release-assets.githubusercontent.com','objects.githubusercontent.com'];
    for ($hop=0;$hop<5;$hop++) {
        worker_allowed_url($url,$allowed);
        $context=stream_context_create(['http'=>[
            'timeout'=>80,'follow_location'=>0,'ignore_errors'=>true,
            'header'=>"User-Agent: mcmanager/0.4.0 (https://github.com/ardenee/mcconfig)\r\n"
        ],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        $input=@fopen($url,'rb',false,$context);
        if (!$input) throw new RuntimeException('Could not download verified Temurin runtime.');
        $headers=stream_get_meta_data($input)['wrapper_data']??[];
        $status=0;$next='';
        foreach($headers as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/i',$line,$m)) $status=(int)$m[1];
            if (stripos($line,'Location:')===0) $next=trim(substr($line,9));
        }
        if ($status>=300 && $status<400) {
            fclose($input);
            if ($next==='' || $hop===4) throw new RuntimeException('Unexpected JRE download redirect.');
            $url=$next;continue;
        }
        if ($status!==200) {
            fclose($input);
            throw new RuntimeException('JRE download returned HTTP '.$status);
        }
        $output=fopen($target,'wb');
        if (!$output) {fclose($input);throw new RuntimeException('Cannot allocate JRE archive.');}
        $hash=hash_init('sha256');$length=0;
        try {
            while (!feof($input)) {
                $chunk=fread($input,1048576);
                if ($chunk===false) throw new RuntimeException('JRE download read failed.');
                $length+=strlen($chunk);
                if ($length>536870912) throw new RuntimeException('JRE archive exceeds 512 MiB safety limit.');
                hash_update($hash,$chunk);
                if ($chunk!=='' && fwrite($output,$chunk)!==strlen($chunk)) throw new RuntimeException('JRE archive write failed.');
            }
        } finally { fclose($input);fclose($output); }
        if (!hash_equals($expected,hash_final($hash))) {
            @unlink($target);
            throw new RuntimeException('Temurin archive SHA256 checksum mismatch.');
        }
        return;
    }
    throw new RuntimeException('Too many JRE redirects.');
}
function worker_managed_java(int $major): ?string {
    if (!in_array($major,[17,21,25],true)) return null;
    $bin=worker_root().'/runtimes/temurin-'.$major.'/bin/java';
    if (!is_file($bin) || !is_executable($bin)) return null;
    return $bin;
}
function worker_choose_java(string $version): string {
    $major=worker_required_java($version);
    $managed=worker_managed_java($major);
    if ($managed!==null) return $managed;
    $system=worker_bin('java');
    if ($system===null) throw new RuntimeException('No Java runtime available; install a managed Temurin JRE from the Server Manager.');
    if ($major>0) {
        $check=worker_java_version($system);
        if ($check['major']<$major) throw new RuntimeException("Minecraft requires Java $major or newer; detected Java ".$check['major']);
    }
    return $system;
}
function worker_install_java(int $major): array {
    $asset=worker_java_asset($major);
    $tar=worker_bin('tar');
    if (!$tar) throw new RuntimeException('tar is needed to install a managed JRE.');
    $runtimes=worker_root().'/runtimes';
    if (!is_dir($runtimes) && !mkdir($runtimes,0700,true)) throw new RuntimeException('Cannot create runtimes directory.');
    $stage=$runtimes.'/.temp-'.bin2hex(random_bytes(6));
    mkdir($stage,0700);
    $archive=$stage.'/temurin.tar.gz';
    try {
        worker_download_java_archive($asset['url'],$asset['sha256'],$archive);
        [$exit,$list]=worker_exec([$tar,'-tzf',$archive],8388608);
        if ($exit!==0 || $list==='') throw new RuntimeException('Unable to inspect the Java archive.');
        $files=explode("\n",$list);
        if (count($files)>50000 || strlen($list)>=8388608) throw new RuntimeException('Java archive listing exceeds safety limits.');
        foreach($files as $entry) {
            if ($entry==='' || str_starts_with($entry,'/') ||
                str_contains($entry,'\\') || preg_match('~(^|/)\.\.(/|$)~',$entry) ||
                preg_match('/[\x00-\x1f]/',$entry)) throw new RuntimeException('Unsafe JRE archive pathname.');
        }
        $extract=$stage.'/extract';mkdir($extract,0700);
        [$exit,$out]=worker_exec([$tar,'-xzf',$archive,'-C',$extract],4096);
        if ($exit!==0) throw new RuntimeException('JRE extraction failed: '.substr($out,0,350));
        $dirs=array_values(array_filter(scandir($extract)?:[],
            fn($p)=>$p!=='.'&&$p!=='..'&&is_dir($extract.'/'.$p)&&!is_link($extract.'/'.$p)));
        if (count($dirs)!==1) throw new RuntimeException('Unexpected Temurin archive structure.');
        $source=realpath($extract.'/'.$dirs[0]);
        $binary=realpath($source.'/bin/java');
        if (!$source || !$binary || !str_starts_with($binary,$source.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('JRE executable escapes its directory.');
        }
        @chmod($binary,0755);
        $runtime=worker_java_version($binary);
        if ($runtime['major']!==$major || $runtime['code']!==0) {
            throw new RuntimeException('Downloaded Java runtime did not execute or reports an unexpected major version.');
        }
        $target=$runtimes.'/temurin-'.$major;
        $old='';
        if (file_exists($target)) {
            $old=$runtimes.'/temurin-'.$major.'-previous-'.date('YmdHis');
            if (!rename($target,$old)) throw new RuntimeException('Cannot preserve previous JRE.');
        }
        if (!rename($source,$target)) {
            if ($old!=='') @rename($old,$target);
            throw new RuntimeException('Could not activate managed Java runtime.');
        }
        file_put_contents($target.'/.mcmanager-release.json',
            json_encode($asset,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
        return ['major'=>$major,'version'=>$runtime['raw'],'path'=>$target.'/bin/java',
            'previous'=>$old,'message'=>'Managed Temurin Java '.$major.' installed side by side; system Java not modified.'];
    } finally {
        // Leave incomplete staging intact only if it contains data requiring manual diagnosis.
        if (is_file($archive)) @unlink($archive);
        if (is_dir($stage.'/extract')) @rmdir($stage.'/extract');
        if (is_dir($stage)) @rmdir($stage);
    }
}
