<?php
declare(strict_types=1);
/** Safe, server-scoped native Minecraft JAR replacement via private worker queue. */
function worker_server_jar_install(string $id,array $server,array $meta,array $params,string $state): array {
    if (($meta['deployment']??'native')!=='native' || ($meta['edition']??'java')!=='java') {
        throw new RuntimeException('Standalone JAR management is for native Java servers only. Container images manage their own server software.');
    }
    if (!in_array($state,['stopped','exited','created'],true)) {
        throw new RuntimeException('Stop the server before installing a Minecraft server JAR.');
    }
    $name=trim((string)($server['jar']??'')) ?: (trim((string)($meta['jar']??'')) ?: 'server.jar');
    if (!preg_match('/^[A-Za-z0-9_.-]{1,112}\.jar$/iD',$name) ||
        str_starts_with($name,'.') || str_contains($name,'..')) {
        throw new RuntimeException('Configure a safe server JAR filename first.');
    }
    $root=valid_root((string)$server['path']);
    $target=$root.'/'.$name;
    if (is_link($target)) throw new RuntimeException('Refusing to overwrite a symlinked Minecraft JAR.');
    $source=(string)($params['source']??'');
    if (!in_array($source,['catalog','url','upload'],true)) {
        throw new RuntimeException('Unsupported Minecraft JAR source.');
    }
    $staged=$root.'/.mcmanager-jar-stage-'.bin2hex(random_bytes(8));
    $version=(string)($params['version']??'');
    $software=(string)($meta['software']??$server['software']??'');
    $artifact=null;
    try {
        worker_progress(8,'Resolving Minecraft server JAR source');
        if ($source==='catalog') {
            if (!in_array($software,['vanilla','paper'],true)) {
                throw new RuntimeException('Official catalog installs are available for registered Vanilla and Paper servers only.');
            }
            $artifact=worker_latest($software,$version!==''?$version:'latest');
            $version=(string)$artifact['version'];
            worker_progress(25,'Downloading official '.$software.' '.$version.' and checking publisher checksum');
            worker_download($artifact,$staged);
        } elseif ($source==='url') {
            $url=(string)($params['url']??'');
            $hash=strtolower((string)($params['checksum']??''));
            $algorithm=match(strlen($hash)) {40=>'sha1',64=>'sha256',default=>''};
            if ($algorithm==='' || !ctype_xdigit($hash)) {
                throw new RuntimeException('A publisher-supplied SHA-1 or SHA-256 checksum is mandatory for direct JAR URLs.');
            }
            worker_allowed_url($url,['piston-data.mojang.com','fill-data.papermc.io']);
            $officialSoftware=(parse_url($url,PHP_URL_HOST)==='piston-data.mojang.com')?'vanilla':'paper';
            if ($software!==$officialSoftware) {
                throw new RuntimeException('Official '.$officialSoftware.' JAR does not match the registered '.$software.' server. Update the server implementation deliberately before installing different software.');
            }
            worker_progress(25,'Downloading HTTPS server JAR with verified checksum');
            worker_download(['url'=>$url,'hash'=>$hash,'algorithm'=>$algorithm],$staged);
        } else {
            $token=(string)($params['upload_id']??'');
            $expected=strtolower((string)($params['checksum']??''));
            if (!preg_match('/^[a-f0-9]{24}$/D',$token) ||
                !preg_match('/^[a-f0-9]{64}$/D',$expected)) {
                throw new RuntimeException('Invalid private JAR upload reference.');
            }
            $upload=manager_upload_path($token);
            if (!is_file($upload) || is_link($upload) || filesize($upload)>536870912) {
                throw new RuntimeException('Uploaded Minecraft JAR is missing or exceeds 512 MiB.');
            }
            if (!hash_equals($expected,(string)hash_file('sha256',$upload))) {
                throw new RuntimeException('Uploaded Minecraft JAR checksum mismatch.');
            }
            if (!copy($upload,$staged)) throw new RuntimeException('Cannot stage server JAR upload.');
            @unlink($upload);
        }
        worker_progress(65,'Inspecting staged Minecraft JAR');
        $zip=new ZipArchive();
        if ($zip->open($staged,ZipArchive::CHECKCONS)!==true) {
            throw new RuntimeException('Downloaded file is not a valid Java JAR archive.');
        }
        try {
            if ($zip->locateName('META-INF/MANIFEST.MF')===false &&
                $zip->locateName('version.json')===false &&
                $zip->locateName('META-INF/versions.list')===false) {
                throw new RuntimeException('Archive is missing Java server JAR metadata; refusing replacement.');
            }
        } finally {$zip->close();}
        $detected=jar_version_from_file($staged);
        $jarVersion=(string)$detected['version'];
        if ($source!=='catalog') {
            if (!jar_version_valid($version) && $jarVersion==='') {
                throw new RuntimeException('The JAR has no detectable Minecraft version. Specify its Minecraft version explicitly before installing.');
            }
            if ($version!=='' && !jar_version_valid($version)) {
                throw new RuntimeException('Invalid Minecraft version entered for this JAR.');
            }
            if ($jarVersion!=='' && $version!=='' && $jarVersion!==$version) {
                throw new RuntimeException('JAR identifies Minecraft '.$jarVersion.', but the entered version was '.$version.'.');
            }
            if ($jarVersion!=='') $version=$jarVersion;
        }
        if (!jar_version_valid($version)) throw new RuntimeException('Could not establish the Minecraft server version.');
        $required=jar_java_required($version);
        if (!$required) throw new RuntimeException('Unknown Java version requirement for Minecraft '.$version);
        $previous=$meta;
        worker_progress(82,'Backing up existing Minecraft server JAR and installing replacement');
        $backup=backup_file($target);
        if (!@rename($staged,$target)) {
            throw new RuntimeException('Cannot replace server JAR. Check filesystem ownership and locks.');
        }
        $meta['version']=$version;
        $meta['jar']=$name;
        if (is_array($artifact)) $meta['build']=$artifact['build']??'';
        try {worker_save_meta($root,$meta);}
        catch(Throwable $e) {
            if ($backup && is_file($backup)) {
                @copy($backup,$target);
                worker_save_meta($root,$previous);
            }
            throw new RuntimeException('Minecraft JAR installed but metadata update failed; previous JAR recovery was attempted. '.$e->getMessage());
        }
        $createdProperties=false;$propertiesWarning='';
        if (!is_file($root.'/server.properties') && !is_link($root.'/server.properties')) {
            try {
                properties_create_basic($server);
                $createdProperties=true;
            } catch(Throwable $e) {
                $propertiesWarning=$e->getMessage();
            }
        }
        return ['message'=>'Minecraft '.$version.' JAR installed; restart the server after installing Java '.$required.' if necessary.'.
                ($propertiesWarning!==''?' Properties require attention: '.$propertiesWarning:''),
            'version'=>$version,'jar'=>$name,'java_required'=>$required,'source'=>$source,
            'created_properties'=>$createdProperties,
            'detected_from'=>$detected['source']?:'official release catalog or administrator version',
            'previous_jar_backup'=>$backup??''];
    } finally {
        if (is_file($staged)) @unlink($staged);
    }
}
