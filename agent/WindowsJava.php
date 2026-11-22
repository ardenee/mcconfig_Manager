<?php
declare(strict_types=1);
/** Verified side-by-side Temurin runtime installation on Windows. */
function windows_java_install(int $major,array $options=[]): array {
    if (PHP_OS_FAMILY!=='Windows')throw new RuntimeException('Windows Temurin installer called on another OS.');
    $asset=worker_java_asset($major);
    $mode=(string)($options['source']??'catalog');
    if (!in_array($mode,['catalog','url','upload'],true))
        throw new InvalidArgumentException('Unsupported Java installation source.');
    $runtimes=worker_root().'/runtimes';
    if (!is_dir($runtimes)&&!mkdir($runtimes,0700,true))
        throw new RuntimeException('Cannot create managed Java runtime directory.');
    $stage=$runtimes.'/.download-'.bin2hex(random_bytes(6));
    if (!mkdir($stage,0700))throw new RuntimeException('Cannot create Java installation staging directory.');
    $zipFile=$stage.'/runtime.zip';
    try {
        worker_progress(10,'Preparing official Windows Temurin JRE '.$major);
        if ($mode==='upload') {
            $token=(string)($options['upload_id']??'');
            if (!preg_match('/^[a-f0-9]{24}$/D',$token))
                throw new RuntimeException('Invalid Java runtime upload token.');
            $upload=manager_base().'/uploads/'.$token.'.bin';
            if (!is_file($upload)||is_link($upload)||(int)filesize($upload)>536870912)
                throw new RuntimeException('Java runtime upload missing or too large.');
            if (!hash_equals($asset['sha256'],strtolower((string)hash_file('sha256',$upload))))
                throw new RuntimeException('Uploaded Java JRE does not match the official Temurin SHA-256.');
            if (!copy($upload,$zipFile))throw new RuntimeException('Cannot stage verified Java runtime.');
        } else {
            $url=$asset['url'];
            if ($mode==='url') {
                $url=(string)($options['url']??'');
                if (strlen($url)>1500 ||
                    !preg_match('~^https://github\.com/adoptium/temurin(?:17|21|25)-binaries/releases/download/~iD',$url))
                    throw new RuntimeException('Only official Adoptium release URLs are supported.');
            }
            worker_download_java_archive($url,$asset['sha256'],$zipFile);
        }
        worker_progress(65,'Extracting verified Windows Temurin runtime');
        $extract=$stage.'/extract';
        if (!mkdir($extract,0700))throw new RuntimeException('Cannot create Java extraction directory.');
        windows_zip_extract_safe($zipFile,$extract,900000000);
        $folders=array_values(array_filter(scandir($extract)?:[],
            fn($item)=>$item!=='.'&&$item!=='..'&&is_dir($extract.'/'.$item)&&!is_link($extract.'/'.$item)));
        if (count($folders)!==1)throw new RuntimeException('Unexpected Temurin runtime archive layout.');
        $source=$extract.'/'.$folders[0];
        $binary=$source.'/bin/java.exe';
        if (windows_executable_architecture($binary)!==$asset['architecture'])
            throw new RuntimeException('Temurin executable architecture is incorrect.');
        $check=worker_java_version($binary);
        if ($check['code']!==0||$check['major']!==$major)
            throw new RuntimeException('Temurin runtime did not launch or has the wrong Java version.');
        worker_progress(90,'Activating Java '.$major.' without changing Windows system Java');
        $target=$runtimes.'/temurin-'.$major;
        $old='';
        if (file_exists($target)) {
            $old=$runtimes.'/temurin-'.$major.'-previous-'.date('YmdHis').'-'.bin2hex(random_bytes(2));
            if (!rename($target,$old))throw new RuntimeException('Cannot preserve existing Java runtime.');
        }
        if (!rename($source,$target)) {
            if ($old!=='')rename($old,$target);
            throw new RuntimeException('Cannot activate managed Windows Java runtime.');
        }
        file_put_contents($target.'/.mcmanager-release.json',
            json_encode($asset,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
        return ['major'=>$major,'path'=>$target.'/bin/java.exe',
            'version'=>$check['raw'],'previous'=>$old,
            'message'=>'Managed Windows Temurin '.$major.' installed; system Java was not changed.'];
    } finally {
        if (is_file($zipFile))@unlink($zipFile);
        // Keep failed extracts for diagnosis; never delete a user's existing JRE.
        if (is_dir($stage.'/extract')&&count(scandir($stage.'/extract')?:[])===2)
            @rmdir($stage.'/extract');
        @rmdir($stage);
    }
}
