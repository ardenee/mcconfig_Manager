<?php
declare(strict_types=1);
/** Minecraft console transport for the private PHP CLI worker.
 * A submitted command is NEVER a shell command, executable name or Docker argument vector.
 */
function worker_console_container_argv(string $docker, string $id, array $meta, string $command): array {
    $command=console_command_input($command);
    $name=worker_docker_name_for($id,$meta);
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/D',$name)) {
        throw new RuntimeException('Invalid registered Minecraft container.');
    }
    if (($meta['edition']??'java')==='bedrock') {
        // itzg Bedrock provides send-command; the response appears in docker logs.
        return [$docker,'exec',$name,'send-command',$command];
    }
    // itzg Java images ship rcon-cli and enable local RCON by default.
    return [$docker,'exec',$name,'rcon-cli',$command];
}
function worker_console_send(string $id, array $server, array $meta, string $input, array &$native): array {
    $command=console_command_input($input);
    $mode=$meta['deployment']??'native';
    if ($mode==='container') {
        if (!worker_docker_owned_for($id,$meta)) {
            throw new RuntimeException('Container identity or data mount verification failed; console disabled.');
        }
        if (worker_docker_status_for($id,$meta)!=='running') {
            throw new RuntimeException('The selected Minecraft container is not running.');
        }
        $cmd=worker_console_container_argv(worker_docker_bin(),$id,$meta,$command);
        [$status,$response]=worker_exec($cmd,8192);
        if ($status!==0) {
            throw new RuntimeException('Minecraft console rejected the command: '.substr($response,0,500));
        }
        return ['message'=>($meta['edition']??'java')==='bedrock'
            ? 'Bedrock command sent. Results appear in the container console output.'
            : 'Java RCON command completed.',
            'response'=>substr($response,0,8192)];
    }
    if ($mode!=='native') throw new RuntimeException('Unsupported console transport.');
    if (!worker_process_running($id,$native)) {
        if (($meta['edition']??'java')==='java') {
            // Native Minecraft Java supports a safe localhost-only RCON route.
            return worker_rcon_command($server,$command);
        }
        throw new RuntimeException('External native Bedrock has no RCON; start it under mcmanager to access its stdin console.');
    }
    // Standalone Linux supervisors own the Minecraft stdin pipe. The main worker
    // submits a signed message, keeping both processes independent.
    if (in_array(PHP_OS_FAMILY,['Linux','Windows'],true) && !isset($native[$id])) {
        if (!supervisor_status($id)['active']) {
            throw new RuntimeException('Native supervisor is not available to receive console commands.');
        }
        if ($command==='stop') supervisor_set_desired($id,false);
        supervisor_enqueue($id,'command',$command);
        return ['message'=>'Command queued for the independent native Minecraft supervisor.',
            'response'=>''];
    }
    $stdin=$native[$id]['stdin']??null;
    if (!is_resource($stdin)) throw new RuntimeException('Native Minecraft console is disconnected.');
    $bytes=@fwrite($stdin,$command."\n");
    if ($bytes!==strlen($command)+1 || !@fflush($stdin)) {
        throw new RuntimeException('Could not deliver Minecraft console command.');
    }
    return ['message'=>'Command sent to native Minecraft server.', 'response'=>''];
}
function worker_console_tail_file(string $file, int $limit=24576): string {
    if (!is_file($file) || !is_readable($file) || is_link($file)) return '';
    $handle=@fopen($file,'rb');
    if (!$handle) return '';
    try {
        $stat=fstat($handle);
        $size=(int)($stat['size']??0);
        if ($size>$limit) fseek($handle,-$limit,SEEK_END);
        $text=stream_get_contents($handle,$limit);
        if (!is_string($text)) return '';
        // Strip incomplete first line if reading from the middle.
        if ($size>$limit) {
            $line=strpos($text,"\n");
            if ($line!==false) $text=substr($text,$line+1);
        }
        return $text;
    } finally {fclose($handle);}
}
function worker_console_sanitize(string $text): string {
    // Strip ANSI color/control sequences, NULs and terminal escape sequences.
    $text=preg_replace('/\x1b(?:\[[0-?]*[ -\/]*[@-~]|\][^\x07\x1b]*(?:\x07|\x1b\\\\))/', '', $text)??'';
    $text=preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $text)??'';
    return substr($text,-24000);
}
function worker_console_read(string $id,array $server,array $meta,array &$native): array {
    $path=valid_root((string)$server['path']);
    $mode=$meta['deployment']??'native';
    $source='';
    $log='';
    if ($mode==='container') {
        if (!worker_docker_owned_for($id,$meta)) throw new RuntimeException('Container identity verification failed.');
        [$exit,$log]=worker_exec([worker_docker_bin(),'logs','--tail','140','--timestamps',
            worker_docker_name_for($id,$meta)],25000);
        if ($exit!==0) throw new RuntimeException('Container log retrieval failed.');
        $source='Minecraft container output';
    } else {
        // Prefer stdout/stderr captured by the worker for supervised native processes.
        // External native servers may have Minecraft's own logs/latest.log.
        foreach(['logs/mcmanager-console.log','logs/latest.log','server.log'] as $candidate) {
            $file=$path.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$candidate);
            $resolved=realpath($file);
            $root=realpath($path);
            // Never follow a Minecraft-controlled log symlink outside the registered world directory.
            if (!$resolved || !$root || !str_starts_with($resolved,$root.DIRECTORY_SEPARATOR)) continue;
            $log=worker_console_tail_file($file);
            if ($log!=='') {$source=$candidate;break;}
        }
        if ($source==='') $source='No file-based console log available';
    }
    return ['server_id'=>$id,'source'=>$source,'output'=>worker_console_sanitize($log),
        'updated'=>time()];
}
function worker_console_capture(string $id,array $server,array $meta,array &$native): void {
    $sub=manager_base().'/console/subscriptions/'.$id;
    // No background Docker-log processing when nobody has the console open.
    if (!is_file($sub) || time()-((int)@file_get_contents($sub))>30) return;
    $payload=worker_console_read($id,$server,$meta,$native);
    worker_write(manager_base().'/console/'.$id.'.json',$payload);
}
