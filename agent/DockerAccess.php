<?php
declare(strict_types=1);
/** Worker-only Docker daemon connectivity; never alters the socket permissions. */
function worker_probe_docker(): array {
    $socket='/var/run/docker.sock';
    $stat=@stat($socket);
    $user=function_exists('posix_geteuid') && function_exists('posix_getpwuid')
        ?(posix_getpwuid(posix_geteuid())['name']??(string)posix_geteuid()):get_current_user();
    $permission=is_array($stat)
        ?sprintf('%04o',(int)$stat['mode']&0777).' uid:'.(int)$stat['uid'].' gid:'.(int)$stat['gid']
        :'not found';
    // These values come from the actual worker runtime, not the web process.
    // Show admin the real socket group instead of guessing "docker"/"root".
    $uid=function_exists('posix_geteuid')?posix_geteuid():null;
    $primaryGroup=function_exists('posix_getegid')?posix_getegid():null;
    $groups=function_exists('posix_getgroups')?posix_getgroups():[];
    if (!is_array($groups)) $groups=[];
    if (is_int($primaryGroup)) $groups[]=$primaryGroup;
    $groups=array_values(array_unique(array_map('intval',$groups)));
    $socketGroup=is_array($stat) && function_exists('posix_getgrgid')
        ?posix_getgrgid((int)$stat['gid']):false;
    $socketGroupName=is_array($socketGroup)
        ?(string)($socketGroup['name']??(string)$stat['gid'])
        :(is_array($stat)?(string)$stat['gid']:'unknown');
    $socketMode=is_array($stat)?sprintf('%04o',(int)$stat['mode']&0777):'unknown';
    $socketGid=is_array($stat)?(int)$stat['gid']:null;
    $isMember=is_int($socketGid)?in_array($socketGid,$groups,true):false;
    $docker=worker_bin('docker');
    $result=['purpose'=>'docker-access','checked'=>time(),'available'=>false,
        'user'=>$user,'socket'=>$socket.' ('.$permission.')',
        'socket_path'=>$socket,'socket_mode'=>$socketMode,
        'socket_group'=>$socketGroupName,'socket_gid'=>$socketGid,
        'socket_owner_uid'=>is_array($stat)?(int)$stat['uid']:null,
        'worker_uid'=>$uid,'worker_groups'=>$groups,
        'worker_in_socket_group'=>$isMember,
        'binary'=>$docker??'not found',
        'message'=>'Docker CLI is not installed or unavailable to the worker.'];
    if (!$docker) return $result;
    try {
        [$exit,$output]=worker_exec([$docker,'version','--format','{{.Server.Version}}'],1024);
        if ($exit===0) {
            $result['available']=true;
            $result['message']='Docker daemon accessible (version '.trim($output).')';
        } else {
            $result['message']=stripos($output,'permission denied')!==false
                ?'Docker socket permission denied for service account '.$user.'. An administrator must explicitly authorize Docker access.'
                :'Docker daemon unavailable: '.substr(trim($output),0,240);
        }
    } catch(Throwable $e) {
        $result['message']='Docker daemon check failed: '.substr($e->getMessage(),0,240);
    }
    return $result;
}
function worker_publish_docker_access(): array {
    $status=worker_probe_docker();
    worker_write(manager_base().'/docker-access.json',$status);
    return $status;
}
