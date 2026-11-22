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
