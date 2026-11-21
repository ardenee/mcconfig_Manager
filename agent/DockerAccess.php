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
    $docker=worker_bin('docker');
    $result=['purpose'=>'docker-access','checked'=>time(),'available'=>false,
        'user'=>$user,'socket'=>$socket.' ('.$permission.')',
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
