<?php
declare(strict_types=1);
/**
 * HMAC-protected installation inventory for server-specific plugin bundles.
 * Manifests live in private manager storage, never in the Minecraft plugins folder.
 */
function plugin_package_id(string $value): string {
    if (!preg_match('/^[a-f0-9]{24}$/D',$value)) {
        throw new InvalidArgumentException('Invalid plugin package ID.');
    }
    return $value;
}
function plugin_upload_name(string $name): string {
    if (!preg_match('/^[A-Za-z0-9_.-]{1,112}\.(?:jar|zip)$/iD',$name) ||
        str_starts_with($name,'.') || str_contains($name,'..')) {
        throw new InvalidArgumentException('Upload a plugin .jar or .zip package with a simple filename.');
    }
    return $name;
}
function plugin_manifest_folder(string $serverId,bool $create=false): string {
    console_server_id($serverId);
    $directory=manager_base().'/plugin_manifests/'.$serverId;
    if ($create && !is_dir($directory) &&
        !mkdir($directory,0700,true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create private plugin package inventory.');
    }
    return $directory;
}
function plugin_manifest_read(string $serverId,string $packageId): array {
    $file=plugin_manifest_folder($serverId).'/'.plugin_package_id($packageId).'.json';
    if (!is_file($file) || is_link($file)) throw new RuntimeException('Plugin package manifest not found.');
    $data=file_get_contents($file);
    if (!is_string($data) || strlen($data)>262144) throw new RuntimeException('Plugin manifest is oversized.');
    $record=manager_unpack($data);
    if (($record['server_id']??'')!==$serverId || ($record['id']??'')!==$packageId ||
        ($record['format']??'')!=='mcmanager-plugin-v1' ||
        !is_array($record['files']??null)) {
        throw new RuntimeException('Plugin package inventory does not match this server.');
    }
    return $record;
}
function plugin_packages(string $serverId): array {
    $directory=plugin_manifest_folder($serverId);
    if (!is_dir($directory)) return [];
    $packages=[];
    foreach(array_slice(glob($directory.'/*.json')?:[],0,250) as $file) {
        $id=basename($file,'.json');
        if (!preg_match('/^[a-f0-9]{24}$/D',$id)) continue;
        try {
            $entry=plugin_manifest_read($serverId,$id);
            if (in_array(($entry['state']??''),['installed','partial','installing'],true)) {
                $packages[]=$entry;
            }
        } catch(Throwable) {
            // Invalid signed records are never trusted for deletion or ownership.
        }
    }
    usort($packages,fn($a,$b)=>(int)($b['created']??0)<=>(int)($a['created']??0));
    return $packages;
}
function plugin_managed_filenames(string $serverId): array {
    $names=[];
    foreach(plugin_packages($serverId) as $package) {
        foreach($package['files'] as $relative=>$record) {
            if (is_string($relative) && preg_match('/^[A-Za-z0-9_.-]+\.jar$/iD',$relative)) {
                $names[$relative]=true;
            }
        }
    }
    return $names;
}
