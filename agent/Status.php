<?php
declare(strict_types=1);
/** Read-only, localhost-only Minecraft protocol and process health probes. */
function worker_varint(int $value): string {
    $out='';
    do {
        $part=$value & 0x7f;$value>>=7;
        if ($value!==0) $part|=0x80;
        $out.=chr($part);
    } while($value!==0);
    return $out;
}
function worker_read_exact($stream,int $n): ?string {
    if ($n<0||$n>524288)return null;
    $output='';
    while(strlen($output)<$n) {
        $chunk=@fread($stream,$n-strlen($output));
        if ($chunk===false||$chunk==='')return null;
        $output.=$chunk;
    }
    return $output;
}
function worker_read_varint($stream): ?int {
    $result=0;
    for($i=0;$i<5;$i++) {
        $byte=worker_read_exact($stream,1);
        if ($byte===null)return null;
        $v=ord($byte);
        $result|=($v&0x7f)<<($i*7);
        if (($v&0x80)===0)return $result;
    }
    return null;
}
function worker_java_ping(int $port): ?array {
    if ($port<1024||$port>65535)return null;
    $socket=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$errstr,0.8,STREAM_CLIENT_CONNECT);
    if (!$socket)return null;
    stream_set_timeout($socket,1);
    try {
        $host='localhost';
        $payload=worker_varint(0).worker_varint(0).worker_varint(strlen($host)).$host.pack('n',$port).worker_varint(1);
        if (@fwrite($socket,worker_varint(strlen($payload)).$payload."\x01\x00")===false)return null;
        $size=worker_read_varint($socket);
        if ($size===null||$size<3||$size>524288)return null;
        $packet=worker_read_exact($socket,$size);
        if ($packet===null)return null;
        $stream=fopen('php://memory','w+b');fwrite($stream,$packet);rewind($stream);
        $packetId=worker_read_varint($stream);
        $jsonSize=worker_read_varint($stream);
        if ($packetId!==0||$jsonSize===null||$jsonSize>524288)return null;
        $body=worker_read_exact($stream,$jsonSize);
        fclose($stream);
        if ($body===null)return null;
        $response=json_decode($body,true);
        if (!is_array($response))return null;
        return ['players_online'=>(int)($response['players']['online']??0),
          'players_max'=>(int)($response['players']['max']??0),
          'server_version'=>substr((string)($response['version']['name']??''),0,100)];
    } catch(Throwable) {return null;}
    finally {fclose($socket);}
}
function worker_bedrock_ping(int $port): ?array {
    if ($port<1024||$port>65535)return null;
    $socket=@stream_socket_client('udp://127.0.0.1:'.$port,$errno,$errstr,0.8,STREAM_CLIENT_CONNECT);
    if (!$socket)return null;
    stream_set_timeout($socket,1);
    try {
        $magic=hex2bin('00ffff00fefefefefdfdfdfd12345678');
        $query="\x01".pack('N2',0,time()).$magic.pack('N2',0,1);
        if (@fwrite($socket,$query)!==strlen($query))return null;
        $response=@fread($socket,2048);
        if (!is_string($response)||strlen($response)<40||ord($response[0])!==0x1c ||
            substr($response,17,16)!==$magic)return null;
        $length=unpack('n',substr($response,33,2))[1]??0;
        if ($length<=0||$length>1024||strlen($response)<35+$length)return null;
        $fields=explode(';',substr($response,35,$length));
        if (count($fields)<6||($fields[0]??'')!=='MCPE')return null;
        return ['players_online'=>(int)$fields[4],'players_max'=>(int)$fields[5],
          'server_version'=>substr((string)$fields[3],0,100)];
    } catch(Throwable) {return null;}
    finally {fclose($socket);}
}
function worker_native_metrics(string $id,array &$native): array {
    if (!worker_process_running($id,$native))return [];
    $state=proc_get_status($native[$id]['process']);
    $pid=(int)($state['pid']??0);
    if ($pid<=0)return [];
    $result=['pid'=>$pid];
    if (is_file('/proc/'.$pid.'/status')) {
        $status=(string)file_get_contents('/proc/'.$pid.'/status');
        if (preg_match('/^VmRSS:\s+(\d+)\s+kB/m',$status,$m))$result['memory_mib']=round((int)$m[1]/1024,1);
        if (preg_match('/^Threads:\s+(\d+)/m',$status,$m))$result['threads']=(int)$m[1];
    }
    if (is_file('/proc/'.$pid.'/stat')) {
        $stat=(string)file_get_contents('/proc/'.$pid.'/stat');
        $end=strrpos($stat,')');
        if ($end!==false) {
            $parts=preg_split('/\s+/',trim(substr($stat,$end+1)))?:[];
            if (isset($parts[12])) {
                $jiffies=(int)$parts[11]+(int)$parts[12];
                static $last=[];
                $now=microtime(true);
                if (isset($last[$id])) {
                    $delta=$now-$last[$id][1];
                    if ($delta>0) $result['cpu_percent']=round(100*($jiffies-$last[$id][0])/($delta*100),1);
                }
                $last[$id]=[$jiffies,$now];
            }
        }
    }
    return $result;
}
function worker_docker_metrics(string $id,array $meta): array {
    try {
        if (!worker_docker_owned_for($id,$meta))return [];
        [$code,$raw]=worker_exec([worker_docker_bin(),'stats','--no-stream',
          '--format','{{json .}}',worker_docker_name_for($id,$meta)],4096);
        if ($code!==0)return [];
        $stats=json_decode($raw,true);
        if (!is_array($stats))return [];
        return ['cpu_percent'=>trim((string)($stats['CPUPerc']??'')),
          'memory'=>trim((string)($stats['MemUsage']??'')),
          'pids'=>trim((string)($stats['PIDs']??''))];
    } catch(Throwable) {return [];}
}
function worker_live_stats(string $id,array $meta,string $state,array &$native): array {
    $result=[];
    $mode=$meta['deployment']??'native';
    $edition=$meta['edition']??'java';
    $port=(int)($meta['port']??0);
    if ($state==='running') {
        $result=$mode==='container'?worker_docker_metrics($id,$meta):worker_native_metrics($id,$native);
    }
    if ($port>=1024 && $port<=65535 && in_array($state,['running','stopped','running-external'],true)) {
        $ping=$edition==='bedrock'?worker_bedrock_ping($port):worker_java_ping($port);
        if ($ping) {
            $result+=$ping;
            if ($state==='stopped' && $mode==='native') $result['external_process']=true;
        }
    }
    return $result;
}
