<?php
declare(strict_types=1);
function dsm_cleanup_eligible(array $row,string $id): bool {
    return ($row['control_mode']??'')==='dsm-on-demand' &&
        ($row['edition']??'')==='bedrock' &&
        ($row['deployment']??'')==='container' &&
        ($row['container_name']??'')==='mcmanager-bedrock-'.$id &&
        preg_match('/^[a-f0-9]{12}$/D',$id)===1;
}
function dsm_cleanup_local_data(array $row,string $id): int {
    if (!dsm_cleanup_eligible($row,$id)) throw new RuntimeException('Unmanaged server data cannot be deleted here.');
    $base=realpath(manager_instances_root());
    $folder=realpath((string)($row['path']??''));
    if (!$base||!$folder||$folder!==$base.DIRECTORY_SEPARATOR.$id||
        is_link($folder)||dirname($folder)!==$base) {
        throw new RuntimeException('Server storage is not the exact managed folder; files were preserved.');
    }
    $walker=new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($folder,FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    $count=0;
    foreach($walker as $entry) {
        if (++$count>200000) throw new RuntimeException('Too many files. Cleanup stopped for inspection.');
        $path=$entry->getPathname();
        if ($entry->isDir()&&!$entry->isLink()) {
            if (!rmdir($path)) throw new RuntimeException('Unable to remove a managed folder.');
        } elseif (!unlink($path)) throw new RuntimeException('Unable to remove a managed file.');
    }
    if (!rmdir($folder)) throw new RuntimeException('Unable to remove empty server folder.');
    return $count;
}
function dsm_cleanup_registration(array &$config,string $id,bool $deleteFiles=false): array {
    $row=$config['servers'][$id]??null;
    if (!is_array($row)||!dsm_cleanup_eligible($row,$id))
        throw new RuntimeException('Not a registered mcmanager-created Bedrock container.');
    $count=$deleteFiles?dsm_cleanup_local_data($row,$id):0;
    unset($config['servers'][$id]);save_config($config);
    return ['files_deleted'=>$count,
        'message'=>$deleteFiles
            ?'Bedrock registration and managed world files removed.'
            :'Bedrock registration removed. Container, image and world files were left unchanged.'];
}
