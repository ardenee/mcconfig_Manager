<?php
declare(strict_types=1);

/** Official native Ubuntu Minecraft Bedrock installer.
 * No Docker, no root, and no arbitrary downloads. Only the stable published
 * serverBedrockLinux artifact from Mojang's HTTPS catalog is accepted.
 */

function linux_bedrock_artifact(): array {
    $catalog=worker_json_url(
        'https://net-secondary.web.minecraft-services.net/api/v1.0/download/links',
        ['net-secondary.web.minecraft-services.net']);
    foreach(($catalog['result']['links']??[]) as $item) {
        if (!is_array($item)||($item['downloadType']??'')!=='serverBedrockLinux')continue;
        $url=(string)($item['downloadUrl']??'');
        if (!preg_match('~^https://www\.minecraft\.net/bedrockdedicatedserver/bin-linux/bedrock-server-(\d+(?:\.\d+){2,4})\.zip$~D',
            $url,$m))
            throw new RuntimeException('Mojang returned an unexpected Linux Bedrock server URL.');
        return ['version'=>$m[1],'url'=>$url];
    }
    throw new RuntimeException('Mojang has not published a current stable Linux Bedrock Dedicated Server archive.');
}

function linux_bedrock_download(string $url,string $dest): void {
    if (!preg_match('~^https://www\.minecraft\.net/bedrockdedicatedserver/bin-linux/bedrock-server-\d+(?:\.\d+){2,4}\.zip$~D',$url))
        throw new RuntimeException('Untrusted Linux Bedrock download URL.');
    $curl=worker_bin('curl');
    if (!$curl)throw new RuntimeException('curl with TLS support is required to download official Ubuntu Bedrock.');
    $args=[$curl,'--fail','--silent','--show-error','--http1.1',
        '--user-agent','Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        '--referer','https://www.minecraft.net/en-us/download/server/bedrock',
        '--header','Accept: */*',
        '--proto','=https','--max-redirs','0',
        '--max-filesize','250000000',
        '--connect-timeout','12','--max-time','480',
        '--speed-time','60','--speed-limit','64',
        '--output',$dest,'--url',$url];
    [$exit,$error]=worker_exec($args,1000);
    if ($exit!==0) {
        @unlink($dest);
        throw new RuntimeException(
            'Official Ubuntu Bedrock server download failed (curl exit '.$exit.'): '.substr($error,0,450));
    }
    $size=is_file($dest)?(int)filesize($dest):0;
    if ($size<1048576||$size>250000000) {
        @unlink($dest);
        throw new RuntimeException('Official Ubuntu Bedrock server ZIP is not within the expected size range.');
    }
    worker_progress(62,'Downloaded official Ubuntu Bedrock ZIP: '.round($size/1048576).' MiB');
}

/** Validate ELF64/AMD64; no attempt to execute a random uploaded executable. */
function linux_bedrock_elf(string $binary): bool {
    if (!is_file($binary)||is_link($binary)||filesize($binary)<512000)return false;
    $handle=@fopen($binary,'rb');
    if (!$handle)return false;
    try {$header=fread($handle,64);}
    finally {fclose($handle);}
    return is_string($header)&&strlen($header)===64 &&
        substr($header,0,4)==="\x7fELF" &&
        ord($header[4])===2 && ord($header[5])===1 &&
        (unpack('v',substr($header,18,2))[1]??0)===62;
}

function linux_bedrock_install(string $path,array $options): array {
    if (!mc_linux_bedrock_supported())
        throw new RuntimeException('Native Bedrock currently requires Ubuntu 22.04+ x86-64. Other Linux distributions are not officially supported.');
    $artifact=linux_bedrock_artifact();
    if (!in_array((string)($options['version']??''),['','latest',$artifact['version']],true))
        throw new RuntimeException('Native Linux Bedrock installer supports only the current official stable release.');
    $temp=$path.'/.mcmanager-bedrock-'.bin2hex(random_bytes(6)).'.zip';
    try {
        worker_progress(18,'Downloading official Ubuntu Bedrock Dedicated Server '.$artifact['version']);
        $upload=(string)($options['bedrock_upload_id']??'');
        if ($upload!=='') {
            $sha=(string)($options['bedrock_upload_sha']??'');
            $name=(string)($options['bedrock_upload_name']??'');
            if (!preg_match('/^[a-f0-9]{24}$/D',$upload)||
                !preg_match('/^[a-f0-9]{64}$/iD',$sha)||
                $name!=='bedrock-server-'.$artifact['version'].'.zip')
                throw new RuntimeException('Linux Bedrock ZIP upload does not match the published stable version.');
            $source=manager_upload_path($upload);
            if (!is_file($source)||is_link($source)||(int)filesize($source)>250000000||
                !hash_equals(strtolower($sha),strtolower((string)hash_file('sha256',$source)))||
                !copy($source,$temp))
                throw new RuntimeException('Linux Bedrock ZIP upload is invalid or incomplete.');
        } else {
            linux_bedrock_download($artifact['url'],$temp);
        }
        // The existing hardened ZIP validator rejects traversal, symlinks,
        // decompression bombs and case-folding collisions on all platforms.
        worker_progress(65,'Validating official Linux Bedrock archive members');
        windows_zip_extract_safe($temp,$path);
        $binary=$path.'/bedrock_server';
        if (!linux_bedrock_elf($binary))
            throw new RuntimeException('Downloaded Ubuntu Bedrock binary is not an ELF64 x86-64 executable.');
        if (!chmod($binary,0700))
            throw new RuntimeException('Cannot set Linux Bedrock executable permission.');
        $properties=$path.'/server.properties';
        if (!is_file($properties)||is_link($properties)||(int)filesize($properties)>131072)
            throw new RuntimeException('Official Linux Bedrock server.properties file is missing or invalid.');
        $raw=file_get_contents($properties);
        if (!is_string($raw))throw new RuntimeException('Cannot read Linux Bedrock configuration.');
        foreach(['server-name'=>(string)$options['name'],'server-port'=>(string)(int)$options['port']] as $key=>$value) {
            if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/D',$key)||str_contains($value,"\n")||str_contains($value,"\r"))
                throw new RuntimeException('Invalid Minecraft Bedrock property.');
            $regex='/^'.preg_quote($key,'/').'\s*=.*$/m';
            $line=$key.'='.$value;
            $raw=preg_match($regex,$raw)?preg_replace($regex,$line,$raw):rtrim($raw)."\n".$line."\n";
        }
        if (file_put_contents($properties,$raw,LOCK_EX)===false)
            throw new RuntimeException('Cannot write Bedrock server.properties.');
        if ($upload!=='')@unlink(manager_upload_path($upload));
        return ['version'=>$artifact['version'],'sha256'=>(string)hash_file('sha256',$temp),
            'source'=>$artifact['url'],'message'=>'Official native Ubuntu Bedrock Dedicated Server installed'];
    } finally {
        if(is_file($temp))@unlink($temp);
    }
}
