<?php
declare(strict_types=1);
/** Private uploads for authenticated administrator jobs only. */
function manager_upload_file(array $upload,string $kind): array {
    $max=in_array($kind,['java','server_jar'],true)?536870912:($kind==='bedrock'?250000000:($kind==='plugin'?134217728:0));
    if (!$max) throw new InvalidArgumentException('Unsupported upload type.');
    if (($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK ||
        !is_string($upload['name']??null) || !is_string($upload['tmp_name']??null) ||
        !is_int($upload['size']??null) || $upload['size']<512 || $upload['size']>$max ||
        !is_uploaded_file($upload['tmp_name'])) {
        throw new RuntimeException('Upload failed or exceeded the upload/PHP profile limit. Check PHP upload_max_filesize and post_max_size.');
    }
    $filename=basename(str_replace('\\','/',$upload['name']));
    if ($kind==='java') {
        $pattern=PHP_OS_FAMILY==='Windows'
            ?'/^[a-zA-Z0-9_.+-]{1,160}\.zip$/iD'
            :'/^[a-zA-Z0-9_.+-]{1,160}\.(?:tar\.gz|tgz)$/iD';
        if (!preg_match($pattern,$filename)) {
            throw new InvalidArgumentException('Choose an official Temurin archive matching this operating system.');
        }
    } elseif ($kind==='bedrock' &&
        (PHP_OS_FAMILY!=='Windows' ||
         !preg_match('/^bedrock-server-\d+(?:\.\d+){2,4}\.zip$/iD',$filename))) {
        throw new InvalidArgumentException('Choose the official Windows Bedrock Dedicated Server ZIP.');
    } elseif ($kind==='plugin' && !preg_match('/^[A-Za-z0-9_.-]{1,112}\.(?:jar|zip)$/iD',$filename)) {
        throw new InvalidArgumentException('Choose a plugin JAR or ZIP package.');
    } elseif ($kind==='server_jar' && !preg_match('/^[A-Za-z0-9_.-]{1,112}\.jar$/iD',$filename)) {
        throw new InvalidArgumentException('Choose a Minecraft server JAR file.');
    }
    manager_initialize();
    $base=manager_base().'/uploads';
    if (!is_dir($base) && !mkdir($base,0700,true) && !is_dir($base)) throw new RuntimeException('Cannot store private uploads.');
    $id=bin2hex(random_bytes(12));
    $destination=$base.'/'.$id.'.bin';
    if (!move_uploaded_file($upload['tmp_name'],$destination)) throw new RuntimeException('Unable to save the uploaded file.');
    @chmod($destination,0600);
    $sha=hash_file('sha256',$destination);
    if (!is_string($sha)) {@unlink($destination);throw new RuntimeException('Failed to hash uploaded file.');}
    return ['upload_id'=>$id,'sha256'=>$sha,'filename'=>$filename,'kind'=>$kind];
}
function manager_upload_path(string $uploadId): string {
    if (!preg_match('/^[a-f0-9]{24}$/D',$uploadId)) throw new InvalidArgumentException('Invalid upload reference.');
    return manager_base().'/uploads/'.$uploadId.'.bin';
}
