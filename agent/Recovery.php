<?php
declare(strict_types=1);
/** Crash recovery. Never replay operations that may already have mutated data. */
function worker_reconcile_interrupted_jobs(): int {
    manager_initialize();
    $count=0;
    foreach(glob(manager_base().'/running/*.json')?:[] as $path) {
        $id=basename($path,'.json');
        if (!preg_match('/^[a-f0-9]{24}$/D',$id)) continue;
        $payload=supervisor_signed_read($path);
        $operation=(string)($payload['operation']??'unknown');
        $server=(string)($payload['server_id']??'');
        worker_write(manager_base().'/done/'.$id.'.json',[
            'id'=>$id,'operation'=>$operation,'server_id'=>$server,'state'=>'failed',
            'message'=>'Management worker interrupted. Operation was NOT replayed. Inspect the server state and any pre-update or restore snapshots before retrying.',
            'interrupted'=>true,'finished'=>time()
        ]);
        @unlink($path);
        $count++;
    }
    if ($count>0) worker_log('recovery','Marked '.$count.' interrupted job(s) failed without replaying.');
    return $count;
}
function worker_reconcile_restore_swaps(): int {
    $cfg=app_config();
    $servers=$cfg['servers']??[];
    if (!is_array($servers)) return 0;
    $recovered=0;
    foreach($servers as $id=>$row) {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{12}$/D',$id) ||
            !is_array($row)) continue;
        $path=$row['path']??'';
        if (!is_string($path) || $path==='' || is_dir($path) || is_link($path)) continue;
        $parent=realpath(dirname($path));
        if (!$parent || !is_writable($parent) || dirname($path)!==$parent) continue;
        $candidates=glob($parent.'/.' . $id . '.mcmanager-previous-*')?:[];
        $valid=[];
        foreach($candidates as $folder) {
            if (!is_dir($folder) || is_link($folder) ||
                !preg_match('/^\.[a-f0-9]{12}\.mcmanager-previous-[a-f0-9]{12}$/D',basename($folder))) continue;
            $meta=worker_meta($folder);
            if (($meta['manager_id']??'')===$id) $valid[]=$folder;
        }
        if (count($valid)!==1) {
            if ($valid) worker_log($id,'Restore recovery ambiguous; leaving previous folders untouched.');
            continue;
        }
        if (@rename($valid[0],$path)) {
            worker_log($id,'Recovered existing server directory after interrupted restore swap.');
            $recovered++;
        } else worker_log($id,'Could not recover interrupted restore swap from '.$valid[0]);
    }
    return $recovered;
}
