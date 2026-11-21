<?php
declare(strict_types=1);
/** Explicit, backup-first conversion from a managed Vanilla Java JAR to Paper.
 * The Minecraft world is not edited; administrators must restart to load Paper.
 */
function worker_paper_preflight(string $id,array $row,array $meta,string $state,string $confirmed): string {
    if (($row['name']??'')!==$confirmed || $confirmed==='') {
        throw new RuntimeException('Confirm this server by entering its exact name.');
    }
    if (($row['edition']??'java')!=='java' || ($row['deployment']??'native')!=='native' ||
        ($meta['edition']??'java')!=='java' || ($meta['deployment']??'native')!=='native' ||
        ($row['software']??'')!=='vanilla' || ($meta['software']??'')!=='vanilla') {
        throw new RuntimeException('Only a managed native Vanilla Java server can be converted to Paper.');
    }
    if (!empty($meta['imported'])) {
        throw new RuntimeException('Imported server software cannot be replaced automatically. Convert using its original installation method and re-import.');
    }
    if ($state!=='stopped') {
        throw new RuntimeException('Stop Minecraft completely before replacing its server software.');
    }
    $version=(string)($meta['version']??$row['version']??'');
    if (!jar_version_valid($version)) {
        throw new RuntimeException('Minecraft version is unknown. Record a release version before changing server software.');
    }
    $jar=(string)($meta['jar']??'server.jar');
    if (!preg_match('/^[A-Za-z0-9_.-]{1,112}\.jar$/iD',$jar) ||
        str_starts_with($jar,'.') || str_contains($jar,'..')) {
        throw new RuntimeException('Configured Minecraft server JAR name is invalid.');
    }
    return $version;
}
function worker_switch_to_paper(string $id,array $row,string $path,array $meta,string $state,string $confirmed): array {
    $version=worker_paper_preflight($id,$row,$meta,$state,$confirmed);
    $jar=(string)($meta['jar']??'server.jar');
    $target=$path.'/'.$jar;
    if (!is_file($target) || is_link($target)) throw new RuntimeException('Vanilla server JAR is missing or unsafe.');
    worker_progress(5,'Checking for a stable Paper build for Minecraft '.$version);
    $artifact=worker_latest('paper',$version);
    if ($artifact['version']!==$version) {
        throw new RuntimeException('Paper does not offer a matching stable Minecraft release.');
    }
    $java=worker_choose_java($version);
    if (worker_java_version($java)['major']<jar_java_required($version)) {
        throw new RuntimeException('A compatible Java runtime must be installed before changing server software.');
    }
    worker_progress(15,'Creating a full pre-conversion backup (including the existing world)');
    $backup=worker_backup($id,$path,'pre-update');
    $stage=$path.'/.mcmanager-paper-'.bin2hex(random_bytes(8)).'.jar';
    $rollback=$path.'/.mcmanager-old-vanilla-'.bin2hex(random_bytes(8)).'.jar';
    $previous=$meta;
    try {
        worker_progress(35,'Downloading verified official Paper server JAR');
        worker_download($artifact,$stage);
        if (!copy($target,$rollback)) {
            throw new RuntimeException('Unable to preserve the original Vanilla JAR for rollback.');
        }
        worker_progress(80,'Activating Paper without modifying world data');
        if (!rename($stage,$target)) {
            throw new RuntimeException('Cannot replace server JAR; the server may still have files open.');
        }
        try {
            $meta['software']='paper';
            $meta['version']=$version;
            $meta['build']=$artifact['build']??'';
            worker_save_meta($path,$meta);
        } catch(Throwable $e) {
            if (!copy($rollback,$target)) {
                throw new RuntimeException('Metadata update failed and JAR rollback failed. Restore the backup: '.basename($backup).'. '.$e->getMessage());
            }
            worker_save_meta($path,$previous);
            throw new RuntimeException('Paper installation failed; original Vanilla JAR restored. '.$e->getMessage());
        }
        return [
            'software'=>'paper','version'=>$version,'build'=>$meta['build'],
            'backup'=>basename($backup),
            'message'=>'Paper '.$version.' installed. Your previous world and Vanilla JAR were backed up. Start the server, inspect logs and then add compatible plugin JARs.'
        ];
    } finally {
        if (is_file($stage)) @unlink($stage);
        if (is_file($rollback)) @unlink($rollback);
    }
}
