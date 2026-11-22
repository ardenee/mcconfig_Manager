<?php
declare(strict_types=1);
/** Exactly one local deployment platform; DSM is Linux but has its own backend. */
function mc_platform(): string {
    if (PHP_OS_FAMILY==='Windows') return 'windows';
    if (PHP_OS_FAMILY==='Linux') {
        if (is_file('/etc.defaults/synoinfo.conf') ||
            (is_dir('/usr/syno') && is_dir('/var/packages'))) return 'dsm';
        return 'linux';
    }
    return 'unsupported';
}

/** Only the officially supported Ubuntu x86-64 hosts offer native BDS. */
function mc_linux_bedrock_supported(): bool {
    if (mc_platform()!=='linux'||PHP_OS_FAMILY!=='Linux'||
        !in_array(strtolower(php_uname('m')),['x86_64','amd64'],true))return false;
    $info=@file_get_contents('/etc/os-release');
    if (!is_string($info)||
        !preg_match('/^ID="?ubuntu"?$/m',$info)||
        !preg_match('/^VERSION_ID="?(\\d+\\.\\d+)"?$/m',$info,$match))return false;
    return version_compare($match[1],'22.04','>=');
}
