<?php
declare(strict_types=1);
/** Read-only Minecraft server version detection, no Java process execution required. */
function jar_version_valid(string $version): bool {
    return preg_match('/^(?:1\.[0-9]{1,2}(?:\.[0-9]{1,2})?|2[6-9]\.[0-9]{1,2}(?:\.[0-9]{1,2})?)$/D',$version)===1;
}
function jar_version_from_file(string $filename): array {
    if (!is_file($filename) || is_link($filename) || !class_exists('ZipArchive')) return ['version'=>'','source'=>''];
    $zip=new ZipArchive();
    if ($zip->open($filename,ZipArchive::CHECKCONS)!==true) return ['version'=>'','source'=>''];
    try {
        $stat=$zip->statName('version.json');
        $raw=is_array($stat) && (int)$stat['size']<=65536 ? $zip->getFromName('version.json') : false;
        if (is_string($raw) && strlen($raw)<65536) {
            $info=json_decode($raw,true);
            $version=is_array($info)?(string)($info['id']??''):'';
            if (jar_version_valid($version)) return ['version'=>$version,'source'=>'JAR embedded version.json'];
        }
        $stat=$zip->statName('META-INF/versions.list');
        $raw=is_array($stat) && (int)$stat['size']<=32768 ? $zip->getFromName('META-INF/versions.list') : false;
        if (is_string($raw) && strlen($raw)<32768) {
            foreach(preg_split('/\r?\n/',$raw)?:[] as $line) {
                if (preg_match('/(?:^|\s)(1\.[0-9]{1,2}(?:\.[0-9]{1,2})?|2[6-9]\.[0-9]{1,2}(?:\.[0-9]{1,2})?)(?:\s|\/)/',$line,$m)
                    && jar_version_valid($m[1])) {
                    return ['version'=>$m[1],'source'=>'JAR Minecraft versions.list'];
                }
            }
        }
        $stat=$zip->statName('META-INF/MANIFEST.MF');
        $raw=is_array($stat) && (int)$stat['size']<=65536 ? $zip->getFromName('META-INF/MANIFEST.MF') : false;
        if (is_string($raw) && strlen($raw)<65536) {
            foreach (['Minecraft-Version','Game-Version','Implementation-Version'] as $field) {
                if (preg_match('/^'.preg_quote($field,'/').':\s*(\S+)/mi',$raw,$m)
                    && jar_version_valid(trim($m[1]))) {
                    return ['version'=>trim($m[1]),'source'=>'JAR '.$field];
                }
            }
        }
        return ['version'=>'','source'=>''];
    } finally {$zip->close();}
}
function jar_detect_version(array $server): array {
    $name=trim((string)($server['jar']??'')) ?: 'server.jar';
    $jar=$name!=='' && preg_match('/^[A-Za-z0-9_.-]+\.jar$/iD',$name)
        ? $server['path'].'/'.$name:'';
    if ($jar!=='' && is_file($jar) && !is_link($jar)) {
        $result=jar_version_from_file($jar);
        if ($result['version']!=='') return $result+['jar_found'=>true];
    }
    $log=$server['path'].'/logs/latest.log';
    if (is_file($log) && !is_link($log) && filesize($log)<33554432) {
        $contents=@file_get_contents($log);
        if (is_string($contents)) {
            foreach([
                '/Starting minecraft server version (1\.\d+(?:\.\d+)?|2[6-9]\.\d+(?:\.\d+)?)/i',
                '/This server is running (?:Paper|Spigot|Purpur|Folia).*?\b(1\.\d+(?:\.\d+)?|2[6-9]\.\d+(?:\.\d+)?)/i'
            ] as $pattern) {
                if (preg_match_all($pattern,$contents,$m) && !empty($m[1])) {
                    $version=end($m[1]);
                    if (jar_version_valid($version)) return ['version'=>$version,'source'=>'last startup log','jar_found'=>$jar!==''&&is_file($jar)];
                }
            }
        }
    }
    $recorded=(string)($server['version']??'');
    if (jar_version_valid($recorded)) return ['version'=>$recorded,'source'=>'registered version (not verified against JAR)',
        'jar_found'=>$jar!==''&&is_file($jar)];
    return ['version'=>'','source'=>'No reliable version detected',
        'jar_found'=>$jar!==''&&is_file($jar)];
}
function jar_java_required(string $version): int {
    if (!jar_version_valid($version)) return 0;
    if (preg_match('/^2[6-9]\./',$version)) return 25;
    if (preg_match('/^1\.(\d+)(?:\.(\d+))?/',$version,$m)) {
        $minor=(int)$m[1];$patch=(int)($m[2]??0);
        if ($minor>=21 || ($minor===20 && $patch>=5)) return 21;
        if ($minor>=18) return 17;
        if ($minor===17) return 16;
        return 8;
    }
    return 0;
}
