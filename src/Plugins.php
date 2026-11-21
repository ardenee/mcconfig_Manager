<?php
declare(strict_types=1);
/** Read-only plugin discovery for supported Java server implementations. */
function plugin_supported(array $server): bool {
    return server_edition($server)==='java' &&
        in_array(strtolower((string)($server['software']??'')),
            ['paper','spigot','bukkit','purpur','folia'],true);
}
function plugin_jar_name(string $name): string {
    if (!preg_match('/^[A-Za-z0-9_.-]{1,112}\.jar$/iD',$name) ||
        str_starts_with($name,'.') || str_contains($name,'..')) {
        throw new InvalidArgumentException('Choose a simple plugin filename ending in .jar (letters, numbers, dots, dashes, underscores).');
    }
    return $name;
}
function plugin_directory(array $server,bool $create=false): string {
    if (!plugin_supported($server)) throw new RuntimeException('This server does not support Bukkit/Paper plugins. Vanilla, Fabric, Forge and Bedrock use different extension systems.');
    $root=valid_root((string)$server['path']);
    $path=$root.'/plugins';
    if (is_link($path)) throw new RuntimeException('Symbolic links are not supported in the plugin directory.');
    if ($create && !is_dir($path) && !mkdir($path,0700,true) && !is_dir($path)) {
        throw new RuntimeException('Cannot create the server plugin directory.');
    }
    if (is_dir($path) && (realpath($path)===false || dirname((string)realpath($path))!==$root)) {
        throw new RuntimeException('Plugin directory escapes the Minecraft server root.');
    }
    return $path;
}
function plugin_inventory(array $server): array {
    if (!plugin_supported($server)) return [];
    $dir=plugin_directory($server);
    if (!is_dir($dir)) return [];
    $files=[];
    foreach(scandir($dir)?:[] as $name) {
        if (!preg_match('/^[A-Za-z0-9_.-]{1,112}\.jar$/iD',$name) ||
            str_starts_with($name,'.') || str_contains($name,'..')) continue;
        $path=$dir.'/'.$name;
        if (!is_file($path)||is_link($path)) continue;
        $files[]=['name'=>$name,'size'=>(int)filesize($path),
            'updated'=>(int)filemtime($path)];
        if (count($files)>=250) break;
    }
    usort($files,fn($a,$b)=>strcasecmp($a['name'],$b['name']));
    return $files;
}
