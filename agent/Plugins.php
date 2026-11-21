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
        $info=$zip->statName('paper-plugin.yml');
        $descriptor=is_array($info) && (int)($info['size']??0)<=65536
            ?$zip->getFromName('paper-plugin.yml'):false;
        $kind='paper-plugin.yml';
        if (!is_string($descriptor)) {
            $info=$zip->statName('plugin.yml');
            $descriptor=is_array($info) && (int)($info['size']??0)<=65536
                ?$zip->getFromName('plugin.yml'):false;
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
    return worker_plugin_apply_tracked($operation,$id,$row,$meta,$params,$state);
}
