<?php
declare(strict_types=1);
/** Dedicated Minecraft supervisor entry point (not web-accessible).
 * Uses the installed PHP CLI and the same private data directory as the main worker.
 */
if (PHP_SAPI!=='cli') {http_response_code(403);exit;}
require __DIR__.'/worker.php';
if (($argv[1]??'')!=='run' || !is_string($argv[2]??null) ||
    !preg_match('/^[a-f0-9]{12}$/D',$argv[2])) {
    fwrite(STDERR,"Usage: php supervisor.php run <registered-server-id>\n");
    exit(2);
}
$id=$argv[2];
try {exit(supervisor_run($id));}
catch(Throwable $e) {
    worker_log($id,'Native supervisor failed: '.$e->getMessage());
    fwrite(STDERR,"Native supervisor failed: ".$e->getMessage()."\n");
    exit(1);
}
