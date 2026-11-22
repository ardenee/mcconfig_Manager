<?php
declare(strict_types=1);
/**
 * Native Windows Minecraft installers. Restricted to mcmanager-owned newly
 * created local storage. Download sources are official Mojang/Adoptium HTTPS.
 * ZIP traversal, symlinks, Windows device names and file collisions are refused.
 */
function windows_zip_extract_safe(string $archive,string $destination,int $maxBytes=1610612736): void {
    if (!extension_loaded('zip'))throw new RuntimeException('PHP zip extension is required for native Windows installations.');
    if (!is_dir($destination)||is_link($destination))throw new RuntimeException('Windows installation directory is invalid.');
    $zip=new ZipArchive();
    if ($zip->open($archive)!==true)throw new RuntimeException('Cannot open downloaded installation ZIP.');
    try {
        if ($zip->numFiles<1||$zip->numFiles>35000)
            throw new RuntimeException('Installation ZIP contains an unexpected number of files.');
        $seen=[];$total=0;
        for ($i=0;$i<$zip->numFiles;$i++) {
            $stat=$zip->statIndex($i);
            if (!is_array($stat))throw new RuntimeException('Invalid ZIP member.');
            $name=(string)($stat['name']??'');
            $path=rtrim($name,'/');
            if ($path===''||strlen($path)>480||
                str_starts_with($name,'/')||str_contains($name,'\\')||
                preg_match('/[\x00-\x1f:*?"<>|]/',$name) ||
                preg_match('~(^|/)\.\.?(/|$)~D',$name)) {
                throw new RuntimeException('Unsafe path in Minecraft installation ZIP.');
            }
            $parts=explode('/',$path);
            foreach($parts as $part) {
                if ($part==='' || str_ends_with($part,'.') || str_ends_with($part,' ')||
                    preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\..*)?$/iD',$part)) {
                    throw new RuntimeException('Unsafe Windows filename in installation ZIP.');
                }
            }
            $canonical=strtolower($path);
            if (isset($seen[$canonical]))throw new RuntimeException('Case-insensitive ZIP path collision.');
            $seen[$canonical]=true;
            $attributes=0;$opsys=0;
            if ($zip->getExternalAttributesIndex($i,$opsys,$attributes)) {
                if ($opsys===3 && (($attributes>>16)&0170000)===0120000)
                    throw new RuntimeException('Symlink in Minecraft installation ZIP.');
            }
            $size=(int)($stat['size']??0);
            if ($size<0||$size>400000000)throw new RuntimeException('Oversized file in installation ZIP.');
            $total+=$size;
            if ($total>$maxBytes)throw new RuntimeException('Installation ZIP expands beyond size limit.');
        }
        if (!$zip->extractTo($destination))throw new RuntimeException('Minecraft installation ZIP extraction failed.');
    } finally { $zip->close(); }
}
function windows_executable_architecture(string $file): string {
    $handle=@fopen($file,'rb');
    if (!$handle)throw new RuntimeException('Windows executable missing.');
    try {
        $head=fread($handle,64);
        if (!is_string($head)||strlen($head)!==64||substr($head,0,2)!=='MZ')
            throw new RuntimeException('Downloaded file is not a Windows executable.');
        $offset=unpack('V',substr($head,60,4))[1]??0;
        if ($offset<64||$offset>16777216||fseek($handle,$offset)!==0)
            throw new RuntimeException('Windows executable PE header invalid.');
        $pe=fread($handle,6);
        if (!is_string($pe)||strlen($pe)!==6||substr($pe,0,4)!=="PE\0\0")
            throw new RuntimeException('Windows executable PE signature invalid.');
        return match(unpack('v',substr($pe,4,2))[1]??0) {
            0x8664=>'x64',0xAA64=>'aarch64',
            default=>throw new RuntimeException('Unsupported Windows executable CPU architecture.')
        };
    } finally { fclose($handle); }
}
function windows_bedrock_artifact(): array {
    $api=worker_json_url('https://net-secondary.web.minecraft-services.net/api/v1.0/download/links',
        ['net-secondary.web.minecraft-services.net']);
    $links=$api['result']['links']??[];
    if (!is_array($links))throw new RuntimeException('Official Bedrock download catalog is unavailable.');
    foreach($links as $item) {
        if (!is_array($item)||($item['downloadType']??'')!=='serverBedrockWindows')continue;
        $url=$item['downloadUrl']??'';
        if (!is_string($url)||!preg_match(
            '~^https://www\.minecraft\.net/bedrockdedicatedserver/bin-win/bedrock-server-(\d+(?:\.\d+){2,4})\.zip$~D',$url,$m)) {
            throw new RuntimeException('Unexpected official Bedrock Windows download URL.');
        }
        return ['url'=>$url,'version'=>$m[1]];
    }
    throw new RuntimeException('Mojang did not publish a Windows Bedrock Dedicated Server link.');
}
function windows_bedrock_download(string $url,string $dest): void {
    if (!preg_match('~^https://www\\.minecraft\\.net/bedrockdedicatedserver/bin-win/bedrock-server-\\d+(?:\\.\\d+){2,4}\\.zip$~D',$url))
        throw new RuntimeException('Untrusted Bedrock download URL.');
    // Windows ships curl.exe, which uses the OS network/certificate stack.
    // PHP stream wrappers may not reach Mojang's large CDN downloads when the
    // host requires its Windows proxy configuration. Never follow redirects to
    // unapproved origins; use an explicit size and execution time limit.
    $curl=worker_bin('curl');
    if (!$curl) throw new RuntimeException('Windows curl.exe is needed for verified Mojang HTTPS transfers.');
    $command=[
        $curl,'--fail','--silent','--show-error','--http1.1',
        // Mojang's CDN can withhold the ZIP body from curl's default
        // user-agent. A browser-like request was verified against the official
        // minecraft.net URL on DEV with an immediate HTTP 206 response.
        '--user-agent','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        '--referer','https://www.minecraft.net/en-us/download/server/bedrock',
        '--header','Accept: */*',
        '--proto','=https','--max-redirs','0',
        '--max-filesize','250000000',
        '--connect-timeout','12','--max-time','480',
        '--speed-time','60','--speed-limit','64',
        '--output',$dest,'--url',$url
    ];
    [$code,$output]=worker_exec($command,1200);
    if ($code!==0) {
        @unlink($dest);
        $reason=$code===28
            ?'Mojang download stalled or timed out; the archive was not installed. '
            :'Official Mojang transfer failed. ';
        throw new RuntimeException($reason.'(curl exit '.$code.'): '.substr($output,0,450));
    }
    $size=is_file($dest)?(int)filesize($dest):0;
    if ($size<1048576||$size>250000000) {
        @unlink($dest);
        throw new RuntimeException('Downloaded Bedrock ZIP has an invalid size.');
    }
    worker_progress(62,'Downloaded official Bedrock ZIP: '.round($size/1048576).' MiB');
}

function windows_bedrock_install(string $path,array $options): array {
    if (PHP_OS_FAMILY!=='Windows')throw new RuntimeException('Windows Bedrock installer called on another platform.');
    $architecture=strtolower(php_uname('m'));
    if (!in_array($architecture,['amd64','x86_64'],true))
        throw new RuntimeException('The official Bedrock Windows server requires a 64-bit Intel/AMD host.');
    $artifact=windows_bedrock_artifact();
    if (!in_array(($options['version']??''),['','latest',$artifact['version']],true))
        throw new RuntimeException('The official Windows Bedrock installer supports the latest release only.');
    $tmp=$path.'/.mcmanager-download-'.bin2hex(random_bytes(6)).'.zip';
    try {
        worker_progress(18,'Downloading official Windows Bedrock Dedicated Server');
        $uploadId=(string)($options['bedrock_upload_id']??'');
        $uploadSha=(string)($options['bedrock_upload_sha']??'');
        $uploadName=(string)($options['bedrock_upload_name']??'');
        if ($uploadId!=='') {
            if (!preg_match('/^[a-f0-9]{24}$/D',$uploadId) ||
                !preg_match('/^[a-f0-9]{64}$/iD',$uploadSha) ||
                $uploadName!=='bedrock-server-'.$artifact['version'].'.zip') {
                throw new RuntimeException('Official Bedrock upload does not match the latest published release.');
            }
            $staged=manager_upload_path($uploadId);
            if (!is_file($staged)||is_link($staged)||(int)filesize($staged)>250000000 ||
                !hash_equals(strtolower($uploadSha),strtolower((string)hash_file('sha256',$staged))) ||
                !copy($staged,$tmp)) {
                throw new RuntimeException('Bedrock ZIP upload failed validation or is unavailable.');
            }
        } else {
            windows_bedrock_download($artifact['url'],$tmp);
        }
        worker_progress(65,'Checking safe ZIP paths and installing native Bedrock');
        windows_zip_extract_safe($tmp,$path);
        $binary=$path.'/bedrock_server.exe';
        if (windows_executable_architecture($binary)!=='x64')
            throw new RuntimeException('Bedrock executable does not match Windows x64.');
        $props=$path.'/server.properties';
        if (!is_file($props))throw new RuntimeException('Bedrock archive does not include server.properties.');
        $raw=file_get_contents($props);
        if (!is_string($raw)||strlen($raw)>131072)throw new RuntimeException('Invalid Bedrock server properties.');
        $replace=function(string $text,string $key,string $value):string{
            $lines=preg_split('/\r\n|\n|\r/',$text)?:[];
            $found=false;
            foreach($lines as &$line) {
                if (preg_match('/^\s*'.preg_quote($key,'/').'\s*=/', $line)) {
                    $line=$key.'='.$value;$found=true;
                }
            }
            unset($line);
            if (!$found)$lines[]=$key.'='.$value;
            return implode("\r\n",$lines)."\r\n";
        };
        $raw=$replace($raw,'server-name',(string)$options['name']);
        $raw=$replace($raw,'server-port',(string)(int)$options['port']);
        file_put_contents($props,$raw,LOCK_EX);
        if (!empty($options['bedrock_upload_id'])) @unlink(manager_upload_path((string)$options['bedrock_upload_id']));
        return ['version'=>$artifact['version'],
            'sha256'=>hash_file('sha256',$tmp),
            'source'=>$artifact['url'],
            'message'=>'Official native Windows Bedrock Dedicated Server installed'];
    } finally {if(is_file($tmp))@unlink($tmp);}
}
