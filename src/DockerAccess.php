<?php
declare(strict_types=1);
/**
 * Signed, short-lived Docker capability from the privileged context of the
 * unprivileged CLI worker. The web process never probes docker.sock directly.
 */
function manager_docker_capability(): array {
    $offline=['available'=>false,'message'=>'Docker management is not enabled for the mcmanager worker.',
        'checked'=>0];
    if (!manager_online()) return $offline+['reason'=>'worker-offline'];
    $path=manager_base().'/docker-access.json';
    if (!is_file($path)||is_link($path)) return $offline+['reason'=>'not-checked'];
    try {
        $raw=file_get_contents($path);
        if (!is_string($raw)||strlen($raw)>8192) throw new RuntimeException('Invalid Docker access record.');
        $value=manager_unpack($raw);
        if (($value['purpose']??'')!=='docker-access' ||
            !is_int($value['checked']??null) ||
            abs(time()-$value['checked'])>90 ||
            !is_bool($value['available']??null)) {
            return $offline+['reason'=>'outdated'];
        }
        return $value;
    } catch(Throwable) {
        return $offline+['reason'=>'invalid'];
    }
}


/**
 * Display actionable, read-only permission guidance using the signed
 * worker-reported socket information. Never guess "docker" group on DSM;
 * never recommend joining the root group or world-writable Docker sockets.
 */
function manager_docker_permission_advice(array $status): array {
    if (!empty($status['available'])) {
        return ['code'=>'ready','message'=>'Docker access is working. No permission changes are required.'];
    }
    $socketGroup=(string)($status['socket_group']??'unknown');
    $socketGid=$status['socket_gid']??null;
    $socketMode=(string)($status['socket_mode']??'unknown');
    $groupMember=!empty($status['worker_in_socket_group']);
    $binary=(string)($status['binary']??'not found');
    if ($binary==='not found') {
        return ['code'=>'cli-missing','message'=>'Install/start Synology Container Manager and ensure the Docker CLI is accessible to the mcmanager package service.'];
    }
    if ($socketGid===null) {
        return ['code'=>'socket-missing','message'=>'The Docker socket was not found. Start Container Manager and check that /var/run/docker.sock exists.'];
    }
    if ((int)$socketGid===0 || strtolower($socketGroup)==='root') {
        return ['code'=>'root-group','message'=>'The Docker socket belongs to the root group. Do NOT add mcmanager to the root group. If Docker control is approved, have a NAS administrator configure a dedicated, restricted Docker-socket group and make the service user a member. Keep socket access limited to that group.'];
    }
    $mode=ctype_digit($socketMode) && preg_match('/^[0-7]{3,4}$/D',$socketMode)
        ?octdec($socketMode):0;
    if (($mode & 0060)!==0060) {
        return ['code'=>'group-permission','message'=>'The Docker socket group '.$socketGroup.' lacks both read and write permission. An administrator must configure a restricted socket-access group with read/write access; never enable access for everyone.'];
    }
    if (!$groupMember) {
        return ['code'=>'group-membership','message'=>'The mcmanager package service account is not a member of the Docker socket group '.$socketGroup.'. If approved, an administrator must add that service account to this existing group, then restart the package to apply its new group membership.'];
    }
    return ['code'=>'inspect','message'=>'The service already belongs to the socket group, but Docker access failed. Check DSM socket ACLs, whether the Docker daemon is running, and the worker service environment. The socket group alone may not explain the failure.'];
}
