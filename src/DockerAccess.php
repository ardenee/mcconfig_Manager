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
