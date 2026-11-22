<?php
declare(strict_types=1);
/** A path installed by Web Station for the Synology mcconfig package.
 * These fixed markers are intentionally independent of open_basedir:
 * restricted PHP-FPM profiles may not be able to stat /usr/syno or /etc.defaults.
 * No user-supplied directory, URL, HTTP header or query parameter is trusted.
 */
function mc_dsm_web_install_path(string $path): bool {
    $path=rtrim(str_replace('\\','/',$path),'/');
    return preg_match('~^/volume[0-9]+/(?:web_packages|web)/mcconfig(?:/|$)~D',$path)===1 ||
        preg_match('~^/var/packages/mcconfig/(?:target|var)(?:/|$)~D',$path)===1 ||
        preg_match('~^/volume[0-9]+/@appdata/mcconfig(?:/|$)~D',$path)===1;
}

/** Exactly one local platform; installed DSM is Linux with a dedicated module. */
function mc_platform(): string {
    if (PHP_OS_FAMILY==='Windows') return 'windows';
    if (PHP_OS_FAMILY==='Linux') {
        if (@is_file('/etc.defaults/synoinfo.conf') ||
            (@is_dir('/usr/syno') && @is_dir('/var/packages'))) return 'dsm';
        // Web Station's open_basedir may hide DSM system directories.
        // The script and document root are trusted values set by the web server.
        foreach ([__DIR__,
            (string)($_SERVER['SCRIPT_FILENAME']??''),
            (string)($_SERVER['DOCUMENT_ROOT']??''),
            (string)(getenv('SYNOPKG_PKGDEST')?:''),
            (string)(getenv('SYNOPKG_PKGVAR')?:''),
            (string)(getenv('MCCONFIG_DATA_DIR')?:'')] as $marker) {
            if (mc_dsm_web_install_path($marker))return 'dsm';
        }
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
