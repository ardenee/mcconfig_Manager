<?php
declare(strict_types=1);
/** Standard Minecraft Java RCON transport, localhost only, with no external binary.
 * Used for adopted native Java servers not supervised by this worker.
 */
function worker_rcon_settings(array $server): array {
    $properties=properties_read($server);
    if (strtolower(trim((string)($properties['enable-rcon']??'false')))!=='true') {
        throw new RuntimeException('This native Java process is external to mcmanager. Enable RCON in server.properties to send console commands without restarting it under mcmanager.');
    }
    $password=(string)($properties['rcon.password']??'');
    if ($password==='' || $password==='change_me' || strlen($password)>256) {
        throw new RuntimeException('Configure a non-default RCON password in server.properties.');
    }
    $port=filter_var($properties['rcon.port']??25575,FILTER_VALIDATE_INT,
        ['options'=>['min_range'=>1024,'max_range'=>65535]]);
    if ($port===false) throw new RuntimeException('Invalid RCON port in server.properties.');
    return [(int)$port,$password];
}
function worker_rcon_write($socket,int $id,int $type,string $body): void {
    $data=pack('V',10+strlen($body)).pack('V',$id).pack('V',$type).$body."\x00\x00";
    $length=strlen($data);
    for($offset=0;$offset<$length;) {
        $n=@fwrite($socket,substr($data,$offset));
        if (!is_int($n) || $n<=0) throw new RuntimeException('RCON connection closed during write.');
        $offset+=$n;
    }
}
function worker_rcon_read($socket): ?array {
    $size=worker_read_exact($socket,4);
    if ($size===null) return null;
    $len=unpack('V',$size)[1]??0;
    if ($len<10 || $len>32768) throw new RuntimeException('Unexpected RCON packet length.');
    $data=worker_read_exact($socket,$len);
    if ($data===null) return null;
    if (substr($data,-2)!=="\x00\x00") throw new RuntimeException('Malformed RCON packet.');
    return ['id'=>unpack('V',substr($data,0,4))[1],
        'type'=>unpack('V',substr($data,4,4))[1],
        'body'=>substr($data,8,-2)];
}
function worker_rcon_command(array $server,string $command): array {
    $command=console_command_input($command);
    [$port,$password]=worker_rcon_settings($server);
    // Deliberately never connect to a user-supplied remote address.
    $socket=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$errstr,1.5,STREAM_CLIENT_CONNECT);
    if (!$socket) throw new RuntimeException('Minecraft RCON is not accepting localhost connections on the configured port.');
    stream_set_timeout($socket,2);
    $id=random_int(1,2147483647);
    try {
        worker_rcon_write($socket,$id,3,$password);
        $authorized=false;
        for($i=0;$i<4;$i++) {
            $packet=worker_rcon_read($socket);
            if (!$packet) break;
            if ($packet['id']===4294967295) throw new RuntimeException('Minecraft RCON authentication failed.');
            if ($packet['id']===$id && $packet['type']===2) {
                $authorized=true;break;
            }
        }
        if (!$authorized) throw new RuntimeException('Minecraft RCON did not confirm authentication.');
        worker_rcon_write($socket,$id,2,$command);
        $response=worker_rcon_read($socket);
        if (!$response || $response['id']!==$id) throw new RuntimeException('Minecraft RCON did not return a valid command response.');
        return ['message'=>'Command completed through localhost-only Minecraft RCON.',
            'response'=>substr($response['body'],0,8192)];
    } finally {fclose($socket);}
}
