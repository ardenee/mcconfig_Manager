<?php
declare(strict_types=1);
/**
 * Plugin file manager for Bukkit/Paper-compatible servers. Not a hot-loader.
 * Operates only on a stopped, verified registered server and server-local plugins/.
 */
function worker_plugin_inspect(string $path): array {
    if (!class_exists('ZipArchive') || !is_file($path) || is_link($path) ||
        filesize($path)<512 || filesize($path)>134217728) {
        throw new RuntimeException('Invalid or oversized plugin JAR (limit 128 MiB).');
    }
    $zip=new ZipArchive();
    if ($zip->open($path,ZipArchive::CHECKCONS)!==true) throw new RuntimeException('Plugin JAR is not a readable ZIP archive.');
    try {
        $descriptor=$zip->getFromName('paper-plugin.yml');
        $kind='paper-plugin.yml';
        if (!is_string($descriptor)) {
            $descriptor=$zip->getFromName('plugin.yml');
            $kind='plugin.yml';
        }
        if (!is_string($descriptor)||strlen($descriptor)>65536 || strlen($descriptor)<8 ||
            !preg_match('/^name\s*:\s*[^\r\n]+/mi',$descriptor) ||
            !preg_match('/^version\s*:\s*[^\r\n]+/mi',$descriptor)) {
            throw new RuntimeException('JAR does not contain a valid plugin.yml or paper-plugin.yml descriptor.');
        }
        return ['descriptor'=>$kind,'sha256'=>hash_file('sha256',$path),
            'bytes'=>(int)filesize($path)];
    } finally {$zip->close();}
}
function worker_plugin_apply(string $operation,string $id,array $row,array $meta,array $params,string $state): array {
    if (!plugin_supported($row)) throw new RuntimeException('Plugins require Bukkit/Paper-compatible Java server software.');
    if (!in_array($state,['stopped','exited','created'],true)) {
        throw new RuntimeException('Stop the Minecraft server before installing or removing plugins.');
    }
    if (($meta['deployment']??'native')==='container' && !worker_docker_owned_for($id,$meta)) {
        throw new RuntimeException('Container identity/mount check failed. Plugin changes refused.');
    }
    $directory=plugin_directory($row,true);
    $name=plugin_jar_name((string)($params['filename']??''));
    $target=$directory.'/'.$name;
    if (is_link($target)) throw new RuntimeException('Refusing symlinked plugin file.');
    if ($operation==='remove_plugin') {
        if (!is_file($target)) throw new RuntimeException('Plugin not found.');
        $quarantine=manager_base().'/removed_plugins/'.$id;
        if (!is_dir($quarantine) && !mkdir($quarantine,0700,true) && !is_dir($quarantine)) {
            throw new RuntimeException('Cannot create private plugin recovery storage.');
        }
        $dest=$quarantine.'/'.date('YmdHis').'-'.bin2hex(random_bytes(4)).'-'.$name;
        if (!rename($target,$dest)) throw new RuntimeException('Plugin could not be removed from its server directory.');
        return ['removed'=>$name,'recovery_copy'=>$dest,
            'message'=>'Plugin removed; original JAR retained privately. Start the server to apply changes.'];
    }
    if ($operation!=='install_plugin') throw new RuntimeException('Unknown plugin operation.');
    $source=(string)($params['source']??'');
    $sha=(string)($params['sha256']??'');
    if (!preg_match('/^[a-f0-9]{64}$/iD',$sha)) {
        throw new RuntimeException('A valid SHA-256 hash is required for plugin installation.');
    }
    $temp=$directory.'/.mcmanager-plugin-'.bin2hex(random_bytes(8));
    try {
        if ($source==='upload') {
            $src=manager_upload_path((string)($params['upload_id']??''));
            if (!is_file($src) || is_link($src) || filesize($src)>134217728) {
                throw new RuntimeException('Uploaded plugin no longer available.');
            }
            if (!copy($src,$temp)) throw new RuntimeException('Could not stage uploaded plugin.');
            @unlink($src);
        } elseif ($source==='url') {
            $url=(string)($params['url']??'');
            if (strlen($url)>1200 || !preg_match('~^https://cdn\.modrinth\.com/data/[A-Za-z0-9/_%.+-]+\.jar(?:\?.*)?$~iD',$url)) {
                throw new RuntimeException('Plugin URL must point to a direct Modrinth CDN .jar download.');
            }
            worker_download_java_archive($url,strtolower($sha),$temp);
        } else throw new RuntimeException('Unsupported plugin download source.');
        $actual=hash_file('sha256',$temp);
        if (!is_string($actual) || !hash_equals(strtolower($sha),strtolower($actual))) {
            throw new RuntimeException('Plugin upload/download failed SHA-256 verification.');
        }
        $details=worker_plugin_inspect($temp);
        if (is_file($target)) backup_file($target);
        if (!rename($temp,$target)) throw new RuntimeException('Could not activate verified plugin.');
        @chmod($target,0644);
        return ['installed'=>$name,'details'=>$details,
            'message'=>'Plugin JAR installed. Restart Minecraft to load it; review compatibility and required dependencies.'];
    } finally {if (is_file($temp)) @unlink($temp);}
}
