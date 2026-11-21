<?php
declare(strict_types=1);
/**
 * Safe self-contained ZIP snapshots and transactional restore.
 * Files are always staged beside the original server directory before cutover.
 * No ZIP-provided path is ever passed directly to extractTo().
 */
function worker_backup_name(string $name): string {
    if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{6}\.zip$/D', $name)) {
        throw new InvalidArgumentException('Invalid mcmanager backup name.');
    }
    return $name;
}
function worker_backup_folder(string $id): string {
    console_server_id($id);
    $dir=manager_base().'/backups/'.$id;
    if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create private backup directory.');
    }
    return $dir;
}
function worker_backup_list(string $id): array {
    $dir=worker_backup_folder($id);
    $result=[];
    foreach (glob($dir.'/*.zip')?:[] as $file) {
        $name=basename($file);
        if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{6}\.zip$/D',$name) || is_link($file)) continue;
        $result[]=['name'=>$name,'size'=>filesize($file),'created'=>filemtime($file)];
    }
    usort($result,fn($a,$b)=>strcmp($b['name'],$a['name']));
    return array_slice($result,0,100);
}
function worker_backup_zip_path(string $id,string $name): string {
    $path=worker_backup_folder($id).'/'.worker_backup_name($name);
    if (!is_file($path) || is_link($path)) throw new RuntimeException('Backup not found or unsafe.');
    return $path;
}
function worker_backup_valid_rel(string $entry): string {
    if (strlen($entry)>2048 || $entry==='' || str_contains($entry,'\\') ||
        str_starts_with($entry,'/') || preg_match('/[\x00-\x1f\x7f]/',$entry)) {
        throw new RuntimeException('Backup contains an unsafe pathname.');
    }
    foreach(explode('/',$entry) as $part) {
        if ($part==='' || $part==='.' || $part==='..' || str_contains($part,':'))
            throw new RuntimeException('Backup contains path traversal.');
    }
    return $entry;
}
function worker_backup_create(string $id,string $path,string $reason='manual'): string {
    if (!class_exists('ZipArchive')) throw new RuntimeException('PHP CLI zip extension required for backup and restore.');
    $path=valid_root($path);
    if (!in_array($reason,['manual','pre-update','pre-restore'],true)) throw new RuntimeException('Invalid backup reason.');
    $backupDir=worker_backup_folder($id);
    $name=date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.zip';
    $out=$backupDir.'/'.$name;
    $zip=new ZipArchive();
    if ($zip->open($out,ZipArchive::CREATE|ZipArchive::EXCL)!==true) throw new RuntimeException('Cannot create backup archive.');
    $manifest=['format'=>'mcmanager-snapshot-v1','server_id'=>$id,
        'created'=>gmdate('c'),'reason'=>$reason,'files'=>[],'dirs'=>[],'total_bytes'=>0];
    $count=0;$max=0;
    try {
        $iter=new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $item) {
            $file=$item->getPathname();
            if ($item->isLink()) throw new RuntimeException('Backup refused: server contains a symbolic link.');
            $rel=substr($file,strlen($path)+1);
            $rel=str_replace(DIRECTORY_SEPARATOR,'/',$rel);
            worker_backup_valid_rel($rel);
            if ($item->isDir()) {
                $manifest['dirs'][$rel]=$item->getPerms()&0777;
                continue;
            }
            if (!$item->isFile()) throw new RuntimeException('Backup refused: unsupported filesystem object.');
            $size=$item->getSize();
            if (++$count>300000 || $size<0 || $max+$size>1099511627776) {
                throw new RuntimeException('Server backup exceeds configured safety limits.');
            }
            $max+=$size;
            $hash=hash_file('sha256',$file);
            if (!is_string($hash)) throw new RuntimeException('Cannot hash server file.');
            if (!$zip->addFile($file,'server/'.$rel)) throw new RuntimeException('Failed to add server file to backup.');
            $manifest['files'][$rel]=[
                'sha256'=>$hash,'bytes'=>$size,'mode'=>$item->getPerms()&0777,
                'mtime'=>$item->getMTime()
            ];
            if ($count%25===0) worker_progress(15,'Hashing and staging '.$count.' Minecraft files for backup');
        }
        $manifest['total_bytes']=$max;
        $manifest['file_count']=$count;
        if (!$zip->addFromString('snapshot.json',json_encode($manifest,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES))) {
            throw new RuntimeException('Cannot write backup manifest.');
        }
        if (!$zip->close()) throw new RuntimeException('Failed to finalize backup.');
        $zip=null;
        worker_backup_verify($id,$name,false);
        // Keep the source snapshot available throughout restore. A safety backup
        // must never prune the very archive the administrator is restoring.
        if ($reason!=='pre-restore') worker_backup_prune($id);
        return $out;
    } catch(Throwable $e) {
        if ($zip instanceof ZipArchive) $zip->close();
        if (is_file($out)) @unlink($out);
        throw $e;
    }
}
function worker_backup_prune(string $id): void {
    $cfg=app_config();
    $keep=(int)($cfg['servers'][$id]['backup_keep']??10);
    $keep=max(3,min(30,$keep));
    $list=worker_backup_list($id);
    foreach(array_slice($list,$keep) as $item) {
        $file=worker_backup_zip_path($id,$item['name']);
        @unlink($file);
    }
}
function worker_backup_verify(string $id,string $name,bool $full=true): array {
    $file=worker_backup_zip_path($id,$name);
    $zip=new ZipArchive();
    if ($zip->open($file,ZipArchive::CHECKCONS)!==true) throw new RuntimeException('Backup archive is unreadable.');
    try {
        if ($zip->numFiles<1 || $zip->numFiles>300002) throw new RuntimeException('Invalid backup entry count.');
        $manifestRaw=$zip->getFromName('snapshot.json');
        if (!is_string($manifestRaw) || strlen($manifestRaw)>33554432) {
            throw new RuntimeException('Backup manifest is missing or oversized.');
        }
        $manifest=json_decode($manifestRaw,true,64,JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['format']??'')!=='mcmanager-snapshot-v1' ||
            ($manifest['server_id']??'')!==$id || !is_array($manifest['files']??null)) {
            throw new RuntimeException('Backup belongs to a different server or is unsupported.');
        }
        $directories=$manifest['dirs']??[];
        if (!is_array($directories)||count($directories)>300000) {
            throw new RuntimeException('Invalid backup directory manifest.');
        }
        foreach($directories as $dir=>$mode) {
            if (!is_string($dir) || !is_int($mode) || $mode<0 || $mode>0777) {
                throw new RuntimeException('Invalid backup directory record.');
            }
            worker_backup_valid_rel($dir);
            if (isset($manifest['files'][$dir])) throw new RuntimeException('Backup directory conflicts with a file.');
        }
        $seen=[];$count=0;$sum=0;
        foreach($manifest['files'] as $relative=>$record) {
            if (!is_string($relative) || !is_array($record)) throw new RuntimeException('Invalid backup manifest file record.');
            worker_backup_valid_rel($relative);
            $sha=$record['sha256']??'';
            $bytes=$record['bytes']??-1;
            if (!is_string($sha)||!preg_match('/^[0-9a-f]{64}$/D',$sha) ||
                !is_int($bytes)||$bytes<0) throw new RuntimeException('Invalid backup file metadata.');
            $seen['server/'.$relative]=[$bytes,$sha];
            $sum+=$bytes;$count++;
        }
        if ($count!==(int)($manifest['file_count']??-1) ||
            $sum!==(int)($manifest['total_bytes']??-1) ||
            $count>300000 || $sum>1099511627776) throw new RuntimeException('Inconsistent backup manifest.');
        foreach(range(0,$zip->numFiles-1) as $i) {
            $stat=$zip->statIndex($i);
            if (!is_array($stat)) throw new RuntimeException('Backup entry unreadable.');
            $entry=(string)$stat['name'];
            if ($entry==='snapshot.json') continue;
            if (!str_starts_with($entry,'server/') || str_ends_with($entry,'/')) {
                throw new RuntimeException('Backup has an unexpected entry type.');
            }
            $relative=substr($entry,7);
            worker_backup_valid_rel($relative);
            if (!isset($seen[$entry]) || $seen[$entry][0]!==(int)$stat['size']) {
                throw new RuntimeException('Backup entry is duplicated or differs from the manifest.');
            }
            $attrs=$zip->getExternalAttributesIndex($i,$opsys,$permissions);
            if ($attrs && ($opsys===ZipArchive::OPSYS_UNIX) &&
                ((($permissions>>16)&0170000)!==0100000) &&
                ((($permissions>>16)&0170000)!==0)) {
                throw new RuntimeException('Archive includes a symlink or special file.');
            }
            unset($seen[$entry]);
        }
        if ($seen) throw new RuntimeException('Backup is missing files declared in manifest.');
        if ($full) {
            foreach($manifest['files'] as $relative=>$record) {
                $stream=$zip->getStream('server/'.$relative);
                if (!is_resource($stream)) throw new RuntimeException('Backup entry failed decompression.');
                $hash=hash_init('sha256');$read=0;
                $chunks=0;
                try {
                    while(!feof($stream)) {
                        $chunk=fread($stream,1048576);
                        if ($chunk===false) throw new RuntimeException('Backup data read failed.');
                        $read+=strlen($chunk);
                        if ($read>$record['bytes']) throw new RuntimeException('Backup file decompressed larger than declared.');
                        hash_update($hash,$chunk);
                        if (++$chunks%64===0) worker_touch_heartbeat();
                    }
                } finally {fclose($stream);}
                if ($read!==$record['bytes'] || !hash_equals($record['sha256'],hash_final($hash))) {
                    throw new RuntimeException('Backup file checksum mismatch: '.$relative);
                }
            }
        }
        return ['name'=>$name,'file_count'=>$count,'bytes'=>$sum,
            'created'=>$manifest['created']??'unknown','verified'=>$full,
            'reason'=>$manifest['reason']??'unknown'];
    } finally {$zip->close();}
}

function worker_backup_remove_staging(string $folder,string $parent): void {
    $parent=realpath($parent);
    if (!$parent || !is_dir($folder) || is_link($folder) ||
        dirname($folder)!==$parent ||
        !preg_match('/^\.[a-f0-9]{12}\.mcmanager-(stage|previous)-[a-f0-9]{12}$/D',basename($folder))) {
        throw new RuntimeException('Refusing to remove a path outside the temporary restore area.');
    }
    $iterator=new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($folder,FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach($iterator as $file) {
        if ($file->isLink() || $file->isFile()) {
            if (!unlink($file->getPathname())) throw new RuntimeException('Failed to clean temporary restore file.');
        } elseif ($file->isDir()) {
            if (!rmdir($file->getPathname())) throw new RuntimeException('Failed to clean temporary restore directory.');
        }
    }
    if (!rmdir($folder)) throw new RuntimeException('Failed to remove temporary restore root.');
}
function worker_backup_restore(string $id,string $path,string $backupName): array {
    $path=valid_root($path);
    if (is_link($path)) throw new RuntimeException('Restore will not replace symlinked server roots.');
    $current=worker_meta($path);
    if (($current['manager_id']??'')!==$id) throw new RuntimeException('Server is not adopted by this manager.');
    if (PHP_OS_FAMILY==='Linux' && !empty($current['imported']) &&
        function_exists('posix_geteuid') && fileowner($path)!==posix_geteuid()) {
        throw new RuntimeException('Imported server ownership differs from the mcmanager worker. Restore would change file ownership/ACLs; correct NAS permissions first or restore with the original server owner.');
    }
    $manifest=worker_backup_verify($id,$backupName,false);
    $parent=realpath(dirname($path));
    if (!$parent || !is_writable($parent)) throw new RuntimeException('Server parent directory is not writable.');
    $free=disk_free_space($parent);
    if ($free!==false && $free<$manifest['bytes']+268435456) {
        throw new RuntimeException('Insufficient free space to stage a safe restore and preserve the current server.');
    }
    worker_progress(10,'Saving a pre-restore safety snapshot');
    $safety=worker_backup_create($id,$path,'pre-restore');
    $suffix=bin2hex(random_bytes(6));
    $stage=$parent.'/.' . $id . '.mcmanager-stage-'.$suffix;
    $previous=$parent.'/.' . $id . '.mcmanager-previous-'.$suffix;
    if (file_exists($stage) || file_exists($previous) || is_link($stage) || is_link($previous)) {
        throw new RuntimeException('Unexpected temporary restore directory exists.');
    }
    if (!mkdir($stage,0700)) throw new RuntimeException('Cannot create restore staging area.');
    $zip=new ZipArchive();
    $cutover=false;
    try {
        if ($zip->open(worker_backup_zip_path($id,$backupName),ZipArchive::CHECKCONS)!==true) {
            throw new RuntimeException('Unable to reopen verified backup.');
        }
        $raw=$zip->getFromName('snapshot.json');
        $snapshot=json_decode((string)$raw,true,64,JSON_THROW_ON_ERROR);
        if (!is_array($snapshot)||($snapshot['server_id']??'')!==$id) {
            throw new RuntimeException('Backup manifest changed during restore.');
        }
        // Recreate empty directories and executable permissions, not just data files.
        foreach(($snapshot['dirs']??[]) as $relative=>$mode) {
            worker_backup_valid_rel($relative);
            $folder=$stage.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
            if (!is_dir($folder) && !mkdir($folder,0700,true) && !is_dir($folder)) {
                throw new RuntimeException('Could not recreate a saved Minecraft directory.');
            }
        }
        $done=0;$total=count($snapshot['files']);
        foreach($snapshot['files'] as $relative=>$info) {
            worker_backup_valid_rel($relative);
            $dest=$stage.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
            $folder=dirname($dest);
            if (!is_dir($folder) && !mkdir($folder,0700,true) && !is_dir($folder)) {
                throw new RuntimeException('Could not create restored server subdirectory.');
            }
            $input=$zip->getStream('server/'.$relative);
            if (!is_resource($input)) throw new RuntimeException('Unable to read a backup entry.');
            $output=@fopen($dest,'xb');
            if (!is_resource($output)) {fclose($input);throw new RuntimeException('Cannot create restored server file.');}
            $hash=hash_init('sha256');$bytes=0;$chunks=0;
            try {
                while (!feof($input)) {
                    $chunk=fread($input,1048576);
                    if ($chunk===false) throw new RuntimeException('Backup extraction failed.');
                    $bytes+=strlen($chunk);
                    if ($bytes>$info['bytes']) throw new RuntimeException('Archive entry exceeds manifest size.');
                    if ($chunk!=='' && fwrite($output,$chunk)!==strlen($chunk)) {
                        throw new RuntimeException('Backup extraction disk write failed.');
                    }
                    hash_update($hash,$chunk);
                    if (++$chunks%64===0) worker_touch_heartbeat();
                }
            } finally {fclose($input);fclose($output);}
            if ($bytes!==$info['bytes'] || !hash_equals($info['sha256'],hash_final($hash))) {
                throw new RuntimeException('Backup failed SHA256 verification while extracting: '.$relative);
            }
            if (isset($info['mode']) && is_int($info['mode'])) {
                @chmod($dest,($info['mode']&0777)&~0022);
            }
            if (isset($info['mtime']) && is_int($info['mtime']) && $info['mtime']>0) {
                @touch($dest,$info['mtime']);
            }
            $done++;
            if ($done%25===0 || $done===$total) {
                worker_progress(20+(int)floor(60*$done/max(1,$total)),
                    'Verifying restored Minecraft files '.$done.'/'.$total);
            }
        }
        foreach(($snapshot['dirs']??[]) as $relative=>$mode) {
            $folder=$stage.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
            if (is_dir($folder) && is_int($mode)) @chmod($folder,($mode&0777)&~0022);
        }
        $zip->close();$zip=null;
        $restored=worker_meta($stage);
        if (($restored['manager_id']??'')!==$id ||
            ($restored['edition']??'')!==($current['edition']??'') ||
            ($restored['deployment']??'')!==($current['deployment']??'')) {
            throw new RuntimeException('Restored server identity, edition or deployment does not match the current registration.');
        }
        // The old directory is never deleted until a complete validated stage exists.
        worker_progress(85,'Switching the validated snapshot into place');
        if (!rename($path,$previous)) throw new RuntimeException('Could not move existing server aside.');
        try {
            if (!rename($stage,$path)) throw new RuntimeException('Could not activate the validated server snapshot.');
            $cutover=true;
        } catch(Throwable $e) {
            if (!rename($previous,$path)) {
                throw new RuntimeException('Restore swap failed; previous server directory remains at '.$previous.'. '.$e->getMessage());
            }
            throw $e;
        }
        $cleanupWarning='';
        try {worker_backup_remove_staging($previous,$parent);}
        catch (Throwable $e) {$cleanupWarning='; old directory retained at '.$previous.' due to cleanup issue';}
        return ['message'=>'Backup restored after checksum verification'.$cleanupWarning,
            'restored'=>$backupName,'pre_restore_backup'=>basename($safety),
            'version'=>$restored['version']??'unknown',
            'files'=>$manifest['file_count']];
    } finally {
        if ($zip instanceof ZipArchive) $zip->close();
        if (is_dir($stage)) {
            try {worker_backup_remove_staging($stage,$parent);}
            catch (Throwable) {} // Never touch original/previous directories in cleanup.
        }
    }
}
