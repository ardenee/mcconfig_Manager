<?php
declare(strict_types=1);
/** Archive-aware, recorded plugin installation and reversible uninstall. */
function worker_plugin_manifest_save(string $id,array $manifest): void {
    if (($manifest['server_id']??'')!==$id) throw new RuntimeException('Plugin manifest server identity mismatch.');
    $file=plugin_manifest_folder($id,true).'/'.plugin_package_id($manifest['id']).'.json';
    worker_write($file,$manifest);
}
function worker_plugin_relative(string $name): string {
    if ($name==='' || strlen($name)>220 || str_contains($name,'\\') ||
        str_starts_with($name,'/') || preg_match('/[\x00-\x1f\x7f]/',$name) ||
        preg_match('/^[A-Za-z]:/',$name)) {
        throw new RuntimeException('Unsafe plugin archive pathname.');
    }
    $parts=explode('/',$name);
    if (count($parts)>10) throw new RuntimeException('Plugin package directory nesting is too deep.');
    foreach($parts as $part) {
        if ($part===''||$part==='.'||$part==='..'||str_contains($part,':')||
            !preg_match('/^[\pL\pN_.()+@-]{1,100}$/uD',$part) ||
            str_ends_with($part,'.')) {
            throw new RuntimeException('Unsafe plugin archive component: '.$part);
        }
    }
    return $name;
}
function worker_plugin_safe_destination(string $root,string $relative): string {
    worker_plugin_relative($relative);
    $path=$root;
    foreach(explode('/',$relative) as $part) {
        $path.=DIRECTORY_SEPARATOR.$part;
        if (is_link($path)) throw new RuntimeException('Refusing symlinked plugin package destination: '.$relative);
    }
    return $path;
}
function worker_plugin_archive_entries(ZipArchive $zip): array {
    if ($zip->numFiles<1 || $zip->numFiles>650) {
        throw new RuntimeException('Plugin archive must contain at most 650 ZIP entries.');
    }
    $files=[];$dirs=[];$unique=[];$total=0;$jarCount=0;
    for($i=0;$i<$zip->numFiles;$i++) {
        $stat=$zip->statIndex($i);
        if (!is_array($stat)) throw new RuntimeException('Unable to inspect plugin archive entry.');
        $original=(string)$stat['name'];
        if ($original===''||strlen($original)>250 || str_contains($original,'\\') ||
            str_starts_with($original,'/') || preg_match('/[\x00-\x1f\x7f]/',$original)) {
            throw new RuntimeException('Archive has an unsafe entry name.');
        }
        if (str_starts_with($original,'__MACOSX/')) continue;
        $isDir=str_ends_with($original,'/');
        $path=$isDir?substr($original,0,-1):$original;
        if ($path==='plugins') {
            if (!$isDir) throw new RuntimeException('Plugin archive cannot replace the plugins directory.');
            continue;
        }
        if (str_starts_with($path,'plugins/')) $path=substr($path,8);
        if ($path==='') continue;
        // Documentation at ZIP root is not installed into the live plugin folder.
        if (!str_contains($path,'/') &&
            preg_match('/^(?:README|LICENSE|NOTICE|CHANGELOG)(?:\.[A-Za-z0-9_-]+)?$/iD',$path)) {
            continue;
        }
        $path=worker_plugin_relative($path);
        $key=strtolower($path);
        if (isset($unique[$key])) throw new RuntimeException('Plugin archive has duplicate or colliding paths: '.$path);
        $unique[$key]=true;
        $attrs=$zip->getExternalAttributesIndex($i,$opsys,$permissions);
        if ($attrs && $opsys===ZipArchive::OPSYS_UNIX) {
            $kind=(($permissions>>16)&0170000);
            if ($kind!==0 && $kind!==($isDir?0040000:0100000)) {
                throw new RuntimeException('Plugin archive contains a symlink or special file: '.$path);
            }
        }
        if ($isDir) {
            $dirs[]=$path;
            continue;
        }
        $bytes=(int)($stat['size']??-1);
        if ($bytes<0 || $bytes>134217728 || ($total+=$bytes)>268435456) {
            throw new RuntimeException('Plugin ZIP exceeds extraction safety limits (128 MiB/file, 256 MiB total).');
        }
        $files[$path]=['index'=>$i,'bytes'=>$bytes];
        if (preg_match('/^[A-Za-z0-9_.-]{1,112}\.jar$/iD',$path)) $jarCount++;
    }
    if (!$files || !$jarCount) {
        throw new RuntimeException('Plugin ZIP must include at least one plugin .jar at the plugins directory root.');
    }
    foreach(array_keys($files) as $relative) {
        $parts=explode('/',$relative);array_pop($parts);
        $parent='';
        foreach($parts as $segment) {
            $parent=$parent===''?$segment:$parent.'/'.$segment;
            if (isset($files[$parent])) throw new RuntimeException('Plugin ZIP has a file/directory collision.');
        }
    }
    foreach($dirs as $relative) {
        if (isset($files[$relative])) throw new RuntimeException('Plugin ZIP mixes a file and folder of the same name.');
    }
    return $files;
}
function worker_plugin_stage_jar(string $source,string $id,array $params,string $stage): array {
    $filename=plugin_jar_name((string)($params['filename']??''));
    $sha=strtolower((string)($params['sha256']??''));
    if (!preg_match('/^[0-9a-f]{64}$/D',$sha)) throw new RuntimeException('A verified SHA-256 is required.');
    if ($source==='upload') {
        $upload=manager_upload_path((string)($params['upload_id']??''));
        if (!is_file($upload)||is_link($upload)||filesize($upload)>134217728)
            throw new RuntimeException('Private plugin upload is missing or too large.');
        if (!copy($upload,$stage.'/'.$filename)) throw new RuntimeException('Cannot stage the uploaded plugin.');
    } elseif ($source==='url') {
        $url=(string)($params['url']??'');
        if (strlen($url)>1200 ||
            !preg_match('~^https://cdn\.modrinth\.com/data/[A-Za-z0-9/_%.+-]+\.jar(?:\?.*)?$~iD',$url)) {
            throw new RuntimeException('Plugin URL must point to a direct Modrinth CDN .jar download.');
        }
        worker_download_java_archive($url,$sha,$stage.'/'.$filename);
    } else throw new RuntimeException('Unsupported plugin source.');
    if (!hash_equals($sha,(string)hash_file('sha256',$stage.'/'.$filename))) {
        throw new RuntimeException('Plugin SHA-256 checksum mismatch.');
    }
    worker_plugin_inspect($stage.'/'.$filename);
    return [$filename=>['sha256'=>$sha,'bytes'=>(int)filesize($stage.'/'.$filename)]];
}
function worker_plugin_stage_zip(string $archive,string $stage): array {
    if (!is_file($archive)||is_link($archive)||filesize($archive)>134217728) {
        throw new RuntimeException('Plugin ZIP is missing or too large (maximum 128 MiB).');
    }
    $zip=new ZipArchive();
    if ($zip->open($archive,ZipArchive::CHECKCONS)!==true) {
        throw new RuntimeException('Plugin package is not a valid ZIP archive.');
    }
    try {
        $entries=worker_plugin_archive_entries($zip);
        $out=[];$done=0;
        foreach($entries as $relative=>$entry) {
            $target=worker_plugin_safe_destination($stage,$relative);
            $parent=dirname($target);
            if (!is_dir($parent)&&!mkdir($parent,0700,true)&&!is_dir($parent)) {
                throw new RuntimeException('Could not create plugin staging directory.');
            }
            $src=$zip->getStreamIndex($entry['index']);
            if (!is_resource($src)) throw new RuntimeException('Could not read ZIP plugin file: '.$relative);
            $fp=@fopen($target,'xb');
            if (!is_resource($fp)) {fclose($src);throw new RuntimeException('Could not create staged plugin file.');}
            $hash=hash_init('sha256');$count=0;
            try {
                while(!feof($src)) {
                    $chunk=fread($src,1048576);
                    if ($chunk===false) throw new RuntimeException('Plugin ZIP decompression failed.');
                    $count+=strlen($chunk);
                    if ($count>$entry['bytes']) throw new RuntimeException('ZIP entry exceeds declared uncompressed size.');
                    if ($chunk!=='' && fwrite($fp,$chunk)!==strlen($chunk)) {
                        throw new RuntimeException('Unable to write extracted plugin file.');
                    }
                    hash_update($hash,$chunk);
                }
            } finally {fclose($src);fclose($fp);}
            if ($count!==$entry['bytes']) throw new RuntimeException('Plugin ZIP entry size mismatch.');
            $out[$relative]=['sha256'=>hash_final($hash),'bytes'=>$count];
            if (preg_match('/^[A-Za-z0-9_.-]{1,112}\.jar$/iD',$relative)) {
                worker_plugin_inspect($target);
            }
            if (++$done%20===0) worker_progress(30+(int)(20*$done/max(1,count($entries))),
                'Verifying extracted plugin files: '.$done.'/'.count($entries));
        }
        return $out;
    } finally {$zip->close();}
}
function worker_plugin_remove_staging(string $folder): void {
    if (!is_dir($folder)||is_link($folder) ||
        !preg_match('/^stage-[a-f0-9]{24}$/D',basename($folder)) ||
        dirname($folder)!==realpath(manager_base().'/plugin_staging')) return;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder,FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $entry) {
        if ($entry->isDir()&&!$entry->isLink()) @rmdir($entry->getPathname());
        else @unlink($entry->getPathname());
    }
    @rmdir($folder);
}
function worker_plugin_install_tracked(string $id,array $row,array $params): array {
    $root=plugin_directory($row,true);
    $source=(string)($params['source']??'');
    if (!in_array($source,['upload','url'],true)) throw new RuntimeException('Unsupported plugin package source.');
    $filename=plugin_upload_name((string)($params['filename']??''));
    $package=bin2hex(random_bytes(12));
    $rootStage=manager_base().'/plugin_staging';
    if (!is_dir($rootStage)&&!mkdir($rootStage,0700,true)&&!is_dir($rootStage))
        throw new RuntimeException('Cannot initialize plugin staging.');
    $stage=$rootStage.'/stage-'.$package;
    if (!mkdir($stage,0700)) throw new RuntimeException('Cannot create plugin staging workspace.');
    $manifestWritten=false;$installed=[];$createdDirs=[];
    try {
        worker_progress(10,'Preparing plugin package '.$filename);
        if (str_ends_with(strtolower($filename),'.zip')) {
            if ($source==='upload') {
                $src=manager_upload_path((string)($params['upload_id']??''));
                if (!is_file($src)||is_link($src) ||
                    !hash_equals(strtolower((string)($params['sha256']??'')),(string)hash_file('sha256',$src))) {
                    throw new RuntimeException('Plugin ZIP upload is missing or checksum verification failed.');
                }
                $files=worker_plugin_stage_zip($src,$stage);
            } else {
                $src=$stage.'/download.zip';
                $sha=strtolower((string)($params['sha256']??''));
                $url=(string)($params['url']??'');
                if (!preg_match('/^[a-f0-9]{64}$/D',$sha) ||
                    !preg_match('~^https://cdn\.modrinth\.com/data/[A-Za-z0-9/_%.+-]+\.zip(?:\?.*)?$~iD',$url)) {
                    throw new RuntimeException('ZIP URL must be a Modrinth CDN HTTPS .zip with SHA-256.');
                }
                worker_download_java_archive($url,$sha,$src);
                if (!mkdir($stage.'/extract',0700)) throw new RuntimeException('Cannot create plugin archive extraction workspace.');
                $files=worker_plugin_stage_zip($src,$stage.'/extract');
                // Move extracted staged files to a common root.
                $stage=$stage.'/extract';
            }
        } else {
            $files=worker_plugin_stage_jar($source,$id,$params,$stage);
        }
        foreach(array_keys($files) as $relative) {
            $dest=worker_plugin_safe_destination($root,$relative);
            if (file_exists($dest)||is_link($dest)) {
                throw new RuntimeException('Plugin package conflicts with existing file: '.$relative.
                    '. Remove the previous plugin or rename it manually first.');
            }
            $parent=dirname($dest);
            if (file_exists($parent)&&!is_dir($parent)) {
                throw new RuntimeException('Plugin package conflicts with an existing file/folder: '.$relative);
            }
        }
        $record=['format'=>'mcmanager-plugin-v1','id'=>$package,'server_id'=>$id,
            'filename'=>$filename,'source'=>$source,'created'=>time(),
            'state'=>'installing','files'=>$files,'dirs'=>[]];
        worker_plugin_manifest_save($id,$record);$manifestWritten=true;
        $count=0;
        foreach($files as $relative=>$info) {
            $dest=worker_plugin_safe_destination($root,$relative);
            $parent=dirname($dest);
            if (!is_dir($parent)) {
                $components=explode('/',dirname($relative));
                $relativeDir='';
                foreach($components as $component) {
                    if ($component==='.') continue;
                    $relativeDir=$relativeDir===''?$component:$relativeDir.'/'.$component;
                    $dir=worker_plugin_safe_destination($root,$relativeDir);
                    if (!is_dir($dir)) {
                        if (!mkdir($dir,0700) && !is_dir($dir)) throw new RuntimeException('Cannot create plugin config directory.');
                        $createdDirs[$relativeDir]=true;
                    }
                }
            }
            $tmp=$parent.'/.mcmanager-copy-'.bin2hex(random_bytes(10));
            if (!copy($stage.'/'.$relative,$tmp)) throw new RuntimeException('Cannot copy staged plugin file.');
            try {
                if (!hash_equals($info['sha256'],(string)hash_file('sha256',$tmp))) {
                    throw new RuntimeException('Plugin copy checksum mismatch.');
                }
                if (file_exists($dest)||is_link($dest) || !rename($tmp,$dest)) {
                    throw new RuntimeException('Cannot commit plugin package file: '.$relative);
                }
                @chmod($dest,0644);
                $installed[$relative]=$info;
            } finally {if (is_file($tmp)) @unlink($tmp);}
            if (++$count%10===0) worker_progress(60+(int)(35*$count/max(1,count($files))),
                'Installing tracked plugin files: '.$count.'/'.count($files));
        }
        $record['dirs']=array_keys($createdDirs);
        $record['state']='installed';
        worker_plugin_manifest_save($id,$record);
        if ($source==='upload') @unlink(manager_upload_path((string)$params['upload_id']));
        return ['installed'=>$filename,'package_id'=>$package,'files'=>count($files),
            'message'=>'Installed '.count($files).' tracked plugin files from '.$filename.'. Restart the server to load plugins.'];
    } catch(Throwable $e) {
        // Rollback is limited to newly written, checksum-matching files.
        foreach($installed as $relative=>$info) {
            try {
                $file=worker_plugin_safe_destination($root,$relative);
                if (is_file($file)&&!is_link($file) &&
                    hash_equals($info['sha256'],(string)hash_file('sha256',$file))) @unlink($file);
            } catch(Throwable) {}
        }
        foreach(array_reverse(array_keys($createdDirs)) as $dir) {
            try { @rmdir(worker_plugin_safe_destination($root,$dir)); } catch(Throwable) {}
        }
        if ($manifestWritten) {
            $record['state']='partial';
            $record['dirs']=array_keys($createdDirs);
            $record['error']=substr($e->getMessage(),0,400);
            worker_plugin_manifest_save($id,$record);
        }
        throw $e;
    } finally {
        // ZIP download extraction stages are nested. Clean the actual stage root.
        $folder=$rootStage.'/stage-'.$package;
        worker_plugin_remove_staging($folder);
    }
}

function worker_plugin_remove_package(string $id,array $server,string $packageId,bool $includeModified=false): array {
    $root=plugin_directory($server,true);
    $record=plugin_manifest_read($id,plugin_package_id($packageId));
    if (!in_array(($record['state']??''),['installed','partial','installing'],true)) {
        throw new RuntimeException('Plugin package is already removed or unavailable.');
    }
    if (count($record['files'])>650) throw new RuntimeException('Plugin package file inventory is too large.');
    $quarantine=manager_base().'/removed_plugins/'.$id.'/'.$packageId;
    if (!is_dir($quarantine)&&!mkdir($quarantine,0700,true)&&!is_dir($quarantine)) {
        throw new RuntimeException('Cannot create private plugin recovery area.');
    }
    $removed=0;$unchanged=0;$modified=[];$paths=[];
    foreach($record['files'] as $relative=>$info) {
        if (!is_string($relative) || !is_array($info) ||
            !preg_match('/^[0-9a-f]{64}$/D',(string)($info['sha256']??''))) {
            throw new RuntimeException('Plugin inventory includes invalid file metadata.');
        }
        $path=worker_plugin_safe_destination($root,$relative);
        if (!file_exists($path)) continue;
        if (!is_file($path)||is_link($path)) {
            $modified[]=$relative;
            continue;
        }
        $actual=hash_file('sha256',$path);
        if (!is_string($actual)) throw new RuntimeException('Could not verify installed plugin file.');
        if (!hash_equals($info['sha256'],$actual) && !$includeModified) {
            $modified[]=$relative;
            continue;
        }
        $dest=worker_plugin_safe_destination($quarantine,$relative);
        if (!is_dir(dirname($dest)) && !mkdir(dirname($dest),0700,true) && !is_dir(dirname($dest))) {
            throw new RuntimeException('Unable to create plugin recovery folder.');
        }
        if (file_exists($dest)||is_link($dest)) {
            // A retry after an interrupted uninstall must not overwrite the recovery copy.
            $modified[]=$relative;
            continue;
        }
        if (!rename($path,$dest)) throw new RuntimeException('Could not move plugin file to recovery: '.$relative);
        $paths[]=$dest;$removed++;
    }
    foreach(array_reverse((array)($record['dirs']??[])) as $relative) {
        if (!is_string($relative)) continue;
        try {
            $dir=worker_plugin_safe_destination($root,$relative);
            if (is_dir($dir)&&!is_link($dir)) @rmdir($dir); // Only empty, originally-created directories.
        } catch(Throwable) {}
    }
    $record['state']=$modified?'partial':'removed';
    $record['last_uninstall']=time();
    $record['preserved_modified']=$modified;
    $record['recovery_folder']=$quarantine;
    worker_plugin_manifest_save($id,$record);
    $message='Removed '.$removed.' recorded plugin files into private recovery storage.';
    if ($modified) $message.=' Preserved '.count($modified).' modified/conflicting file(s): '.implode(', ',array_slice($modified,0,8)).
        '. You can choose Remove including modified tracked files to finish uninstalling.';
    return [
        'removed'=>$record['filename']??$packageId,
        'package_id'=>$packageId,
        'removed_files'=>$removed,
        'preserved_modified'=>$modified,
        'recovery_folder'=>$quarantine,
        'recovery_copy'=>count($paths)===1?$paths[0]:$quarantine,
        'message'=>$message
    ];
}
function worker_plugin_apply_tracked(string $operation,string $id,array $row,array $meta,array $params,string $state): array {
    if (!plugin_supported($row)) throw new RuntimeException('Plugins require Bukkit/Paper-compatible Java server software.');
    if (!in_array($state,['stopped','exited','created'],true)) {
        throw new RuntimeException('Stop the Minecraft server before installing or removing plugins.');
    }
    if (($meta['deployment']??'native')==='container' && !worker_docker_owned_for($id,$meta)) {
        throw new RuntimeException('Container identity/mount check failed. Plugin changes refused.');
    }
    if ($operation==='install_plugin') return worker_plugin_install_tracked($id,$row,$params);
    if ($operation!=='remove_plugin') throw new RuntimeException('Unsupported plugin operation.');
    if (!empty($params['package_id'])) {
        return worker_plugin_remove_package($id,$row,(string)$params['package_id'],!empty($params['remove_modified']));
    }
    // Compatibility: JARs installed before manifests were introduced.
    // Never delete a package-managed JAR through an untracked removal request.
    $name=plugin_jar_name((string)($params['filename']??''));
    if (isset(plugin_managed_filenames($id)[$name])) {
        throw new RuntimeException('This JAR belongs to a tracked plugin package. Use Remove package instead.');
    }
    $directory=plugin_directory($row,true);
    $path=$directory.'/'.$name;
    if (!is_file($path)||is_link($path)) throw new RuntimeException('Unmanaged plugin JAR not found or unsafe.');
    $quarantine=manager_base().'/removed_plugins/'.$id.'/legacy';
    if (!is_dir($quarantine)&&!mkdir($quarantine,0700,true)&&!is_dir($quarantine)) {
        throw new RuntimeException('Cannot create recovery storage for legacy plugin.');
    }
    $dest=$quarantine.'/'.date('YmdHis').'-'.bin2hex(random_bytes(6)).'-'.$name;
    if (!rename($path,$dest)) throw new RuntimeException('Cannot move legacy JAR into recovery.');
    return ['removed'=>$name,'recovery_copy'=>$dest,'removed_files'=>1,
        'message'=>'Legacy plugin JAR removed and retained privately. Plugin-generated data remains untouched.'];
}
