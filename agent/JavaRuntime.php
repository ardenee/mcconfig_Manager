<?php
declare(strict_types=1);
/** Side-by-side Eclipse Temurin Java runtimes, confined to mcmanager-owned storage.
 * No package manager commands and no modifications to system Java.
 * Source: api.adoptium.net, checksum verified before extraction.
 */
function worker_java_architecture(): string {
    if (!in_array(PHP_OS_FAMILY,['Linux','Windows'],true))
        throw new RuntimeException('Managed Java requires Windows or Linux.');
    return match(strtolower(php_uname('m'))) {
        'x86_64','amd64'=>'x64',
        'aarch64','arm64'=>'aarch64',
        default=>throw new RuntimeException('No supported Temurin JRE for CPU '.php_uname('m'))
    };
}
function worker_java_validate_binary_arch(string $file,string $arch): void {
    $head=@file_get_contents($file,false,null,0,20);
    if (!is_string($head) || strlen($head)<20 || substr($head,0,4)!=="\x7fELF" ||
        ord($head[4])!==2 || ord($head[5])!==1) {
        throw new RuntimeException('Java executable is not a supported 64-bit Linux ELF binary.');
    }
    $machine=unpack('v',substr($head,18,2))[1]??0;
    $wanted=match($arch) {'x64'=>62,'aarch64'=>183,default=>0};
    if ($machine!==$wanted) throw new RuntimeException('Java executable architecture does not match the NAS CPU.');
}
function worker_java_asset(int $major): array {
    if (!in_array($major,[17,21,25],true)) throw new InvalidArgumentException('Managed Java supports LTS 17, 21 and 25.');
    $arch=worker_java_architecture();
    $os=PHP_OS_FAMILY==='Windows'?'windows':'linux';
    $url="https://api.adoptium.net/v3/assets/latest/$major/hotspot?architecture=$arch&image_type=jre&os=$os&vendor=eclipse";
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
    if (!str_ends_with($name,$os==='windows'?'.zip':'.tar.gz'))
        throw new RuntimeException('Unexpected Temurin archive format for '.$os.'.');
    return ['major'=>$major,'architecture'=>$arch,'url'=>$link,'sha256'=>strtolower($sha),
        'release'=>(string)($assets[0]['release_name']??'unknown')];
}
function worker_download_java_archive(string $url,string $expected,string $target): void {
    $allowed=['github.com','release-assets.githubusercontent.com','objects.githubusercontent.com','api.adoptium.net','cdn.modrinth.com'];
    for ($hop=0;$hop<5;$hop++) {
        worker_allowed_url($url,$allowed);
        $context=stream_context_create(['http'=>[
            'timeout'=>80,'follow_location'=>0,'ignore_errors'=>true,
            'header'=>"User-Agent: mcmanager/0.5.1 (https://github.com/ardenee/mcconfig)\r\n"
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
        $hash=hash_init('sha256');$length=0;$lastReported=0;
        $expectedSize=0;
        foreach($headers as $line) {
            if (preg_match('/^Content-Length:\s*(\d+)/i',$line,$m)) $expectedSize=(int)$m[1];
        }
        try {
            while (!feof($input)) {
                $chunk=fread($input,1048576);
                if ($chunk===false) throw new RuntimeException('JRE download read failed.');
                $length+=strlen($chunk);
                if ($length>536870912) throw new RuntimeException('JRE archive exceeds 512 MiB safety limit.');
                hash_update($hash,$chunk);
                if ($chunk!=='' && fwrite($output,$chunk)!==strlen($chunk)) throw new RuntimeException('Archive write failed.');
                if ($length-$lastReported>=4194304) {
                    $lastReported=$length;
                    $percent=$expectedSize>0
                        ?12+(int)floor(min(30,30*$length/$expectedSize))
                        :12+(int)min(30,$length/4194304);
                    worker_progress($percent,'Downloading verified archive: '.round($length/1048576,1).' MiB received');
                }
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
    $bin=worker_root().'/runtimes/temurin-'.$major.'/bin/java'.
        (PHP_OS_FAMILY==='Windows'?'.exe':'');
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
function worker_install_java(int $major,array $options=[]): array {
    if (PHP_OS_FAMILY==='Windows')return windows_java_install($major,$options);
    $asset=worker_java_asset($major);
    $mode=(string)($options['source']??'catalog');
    if (!in_array($mode,['catalog','url','upload'],true)) {
        throw new InvalidArgumentException('Invalid Java download source.');
    }
    $tar=worker_bin('tar');
    if (!$tar) throw new RuntimeException('tar is needed to install a managed JRE.');
    $runtimes=worker_root().'/runtimes';
    if (!is_dir($runtimes) && !mkdir($runtimes,0700,true)) throw new RuntimeException('Cannot create runtimes directory.');
    $stage=$runtimes.'/.temp-'.bin2hex(random_bytes(6));
    mkdir($stage,0700);
    $archive=$stage.'/temurin.tar.gz';
    try {
        worker_progress(12,'Validating Java '.$major.' for NAS '.$asset['architecture']);
        if ($mode==='url') {
            $url=(string)($options['url']??'');
            if (strlen($url)>1500 ||
                !preg_match('~^https://(?:github\\.com/adoptium/temurin[0-9]+-binaries/releases/download/|api\\.adoptium\\.net/v3/binary/latest/)~i',$url)) {
                throw new RuntimeException('Only official Adoptium Temurin release URLs are accepted.');
            }
            // Every source must equal the current upstream checksum for this
            // Java major and CPU architecture; user-supplied archives cannot bypass it.
            worker_download_java_archive($url,$asset['sha256'],$archive);
        } elseif ($mode==='upload') {
            $token=(string)($options['upload_id']??'');
            if (!preg_match('/^[a-f0-9]{24}$/D',$token)) throw new RuntimeException('Invalid uploaded Java archive reference.');
            $staged=manager_base().'/uploads/'.$token.'.bin';
            if (!is_file($staged)||is_link($staged)||filesize($staged)>536870912) {
                throw new RuntimeException('Java upload not found or exceeds 512 MiB.');
            }
            if (!hash_equals($asset['sha256'],(string)hash_file('sha256',$staged))) {
                throw new RuntimeException('Uploaded Java archive differs from current official Temurin '.$major.' '.$asset['architecture'].' checksum.');
            }
            if (!copy($staged,$archive)) throw new RuntimeException('Could not stage uploaded Java archive.');
            @unlink($staged);
        } else {
            worker_download_java_archive($asset['url'],$asset['sha256'],$archive);
        }
        worker_progress(45,'Verifying Java archive and CPU architecture');
        [$exit,$list]=worker_exec([$tar,'-tzf',$archive],8388608);
        if ($exit!==0 || $list==='') throw new RuntimeException('Unable to inspect the Java archive.');
        $files=explode("\n",$list);
        if (count($files)>50000 || strlen($list)>=8388608) throw new RuntimeException('Java archive listing exceeds safety limits.');
        foreach($files as $entry) {
            if ($entry==='' || str_starts_with($entry,'/') ||
                str_contains($entry,'\\') || preg_match('~(^|/)\.\.(/|$)~',$entry) ||
                preg_match('/[\x00-\x1f]/',$entry)) throw new RuntimeException('Unsafe JRE archive pathname.');
        }
        worker_progress(65,'Extracting verified Linux Java runtime');
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
        worker_java_validate_binary_arch($binary,$asset['architecture']);
        @chmod($binary,0755);
        worker_progress(83,'Validating installed Java major version');
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
