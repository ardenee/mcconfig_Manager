<?php
declare(strict_types=1);
/**
 * Experimental one-request DSM Container Manager approval.
 * Authenticates only to DSM on NAS loopback, never to a supplied URL.
 * sid/CSRF are local variables and are always discarded after logout.
 * No job queue, persistent session, credential files, or Docker socket access.
 */
function dsm_approval_post(array $params,array $headers=[]): array {
    if (!extension_loaded('curl')) {
        throw new RuntimeException('Enable the PHP curl extension in the mcconfig Web Station PHP 8.2 profile.');
    }
    $url='http://127.0.0.1:5000/webapi/entry.cgi';
    $handle=curl_init($url);
    if ($handle===false) throw new RuntimeException('Unable to initialize local DSM API connection.');
    try {
        $opts=[
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>http_build_query($params,'','&',PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER=>array_merge(['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],$headers),
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>3,
            CURLOPT_TIMEOUT=>18,
            CURLOPT_MAXREDIRS=>0,
            CURLOPT_PROXY=>'',
            CURLOPT_NOPROXY=>'127.0.0.1',
            CURLOPT_USERAGENT=>'mcmanager-DSM-OneAction/1',
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) $opts[CURLOPT_PROTOCOLS_STR]='http';
        else $opts[CURLOPT_PROTOCOLS]=CURLPROTO_HTTP;
        curl_setopt_array($handle,$opts);
        $result=curl_exec($handle);
        $status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
        if (!is_string($result)) {
            throw new RuntimeException('DSM localhost API unavailable: '.substr(curl_error($handle),0,130));
        }
        if ($status!==200) {
            throw new RuntimeException($status>=300 && $status<400
                ?'DSM redirected the loopback request. DSM HTTP port 5000 may be configured to force HTTPS. The experimental adapter did not follow the redirect or send credentials elsewhere.'
                :'DSM API returned HTTP '.$status.'. Check DSM WebAPI on local port 5000.');
        }
        if (strlen($result)>1048576) throw new RuntimeException('DSM API reply exceeded 1 MiB.');
        $payload=json_decode($result,true);
        if (!is_array($payload)||!isset($payload['success'])) {
            throw new RuntimeException('Unexpected response from the DSM API.');
        }
        return $payload;
    } finally {curl_close($handle);}
}
function dsm_approval_checked(array $reply,string $what): array {
    if (($reply['success']??false)!==true) {
        $code=$reply['error']['code']??'unknown';
        // Never include DSM responses or token values in displayed errors.
        $suffix=$what==='DSM login'
            ?'Verify the DSM administrator username, password, login privileges or current one-time code.'
            :'Check Container Manager application permissions and the requested operation on this NAS.';
        throw new RuntimeException($what.' failed (DSM error '.(is_int($code)?$code:'unknown').'). '.$suffix);
    }
    return is_array($reply['data']??null)?$reply['data']:[];
}
function dsm_approval_container_name(string $name): string {
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/D',$name)) {
        throw new InvalidArgumentException('Invalid Docker container name.');
    }
    return $name;
}
function dsm_approval_container_matches(array $details,array $server,string $name): bool {
    // Some DSM versions put the Docker inspect result directly in data, others
    // return it under a "container" key. Fail closed on any unknown shape.
    $info=is_array($details['container']??null)?$details['container']:$details;
    if (is_array($info['info']??null)) $info=$info['info'];
    $actual=(string)($info['Name']??$info['name']??'');
    if ($actual!=='' && ltrim($actual,'/')!==$name) return false;
    $image=(string)($info['Config']['Image']??$info['image']??$info['ImageName']??'');
    $imageOk=(($server['edition']??'java')==='bedrock')
        ?preg_match('~^(?:(?:docker\.io|registry-1\.docker\.io|index\.docker\.io)/)?itzg/minecraft-bedrock-server(?::|@|$)~iD',$image)
        :preg_match('~^(?:docker\.io/)?itzg/minecraft-server(?::|@|$)~iD',$image);
    if (!$imageOk) return false;
    $registered=realpath((string)($server['path']??''));
    if ($registered===false) return false;
    $mounts=$info['Mounts']??$info['mounts']??$info['volumes']??$info['volume_bindings']??[];
    if (!is_array($mounts)) return false;
    foreach($mounts as $mount) {
        if (!is_array($mount))continue;
        if (($mount['Destination']??$mount['destination']??$mount['mount_point']??$mount['container_path']??'')!=='/data')continue;
        if (realpath((string)($mount['Source']??$mount['source']??$mount['host_volume_file']??$mount['host_path']??''))===$registered)return true;
    }
    return false;
}
/**
 * $send callback allows offline simulation. Do not persist or log $password or
 * the returned DSM token/sid. Only registered/confirmed itzg + /data containers
 * can be started or stopped.
 */
function dsm_approval_once(
    string $account,string $password,string $operation,
    ?array $server=null,string $otp='',?callable $send=null
): array {
    if (!in_array($operation,['test','start','stop','bedrock-image','bedrock-pull','bedrock-poll','bedrock-create','bedrock-verify'],true)) {
        throw new InvalidArgumentException('Unsupported DSM one-time operation.');
    }
    if (!preg_match('/^[\w.@-]{1,64}$/D',$account) ||
        $password==='' || strlen($password)>1024) {
        throw new InvalidArgumentException('Enter a valid DSM account and password.');
    }
    if ($otp!=='' && !preg_match('/^[0-9]{6,8}$/D',$otp)) {
        throw new InvalidArgumentException('Enter a valid DSM one-time verification code.');
    }
    if (in_array($operation,['start','stop','bedrock-verify'],true) && (!$server ||
        ($server['deployment']??'native')!=='container' ||
        !in_array(($server['install_state']??'ready'),
            $operation==='bedrock-verify'?['ready','imported','unverified']:['ready','imported'],true))) {
        throw new RuntimeException('Select a registered Minecraft container; start/stop requires a verified, ready server.');
    }
    $send=$send??'dsm_approval_post';
    $session='';
    $token='';
    try {
        $fields=[
            'api'=>'SYNO.API.Auth','version'=>'6','method'=>'login',
            'account'=>$account,'passwd'=>$password,
            'session'=>'Docker','format'=>'sid',
            'enable_syno_token'=>'yes','enable_device_token'=>'no'
        ];
        if ($otp!=='') $fields['otp_code']=$otp;
        $login=dsm_approval_checked($send($fields),'DSM login');
        $session=(string)($login['sid']??'');
        $token=(string)($login['synotoken']??'');
        if ($session==='' || strlen($session)>512) {
            throw new RuntimeException('DSM login succeeded but no session ID was returned.');
        }
        $api=function(array $params)use($send,&$session,&$token):array {
            $params['_sid']=$session;
            if ($token!=='') $params['SynoToken']=$token;
            $headers=$token!==''?['X-SYNO-TOKEN: '.$token]:[];
            return $send($params,$headers);
        };
        if (in_array($operation,['bedrock-image','bedrock-pull','bedrock-poll','bedrock-create'],true)) {
            return dsm_bedrock_api_action($api,$operation,$server);
        }
        if ($operation==='bedrock-verify') {
            $name=dsm_approval_container_name((string)($server['container_name']??''));
            $details=dsm_approval_checked($api([
                'api'=>'SYNO.Docker.Container','version'=>'1',
                'method'=>'get','name'=>json_encode($name,JSON_UNESCAPED_SLASHES)
            ]),'Bedrock container verification');
            if (!dsm_approval_container_matches($details,$server,$name)) {
                throw new RuntimeException('DSM did not confirm the container image and /data bind mount. Nothing was started.');
            }
            return ['operation'=>'bedrock-verify','verified'=>true,'container'=>$name,
                'message'=>'DSM verified the registered Bedrock container. Approve Start separately when ready.'];
        }
        if ($operation==='test') {
            $found=dsm_approval_checked($api([
                'api'=>'SYNO.Docker.Container','version'=>'1','method'=>'list',
                'limit'=>'-1','offset'=>'0','type'=>'all'
            ]),'Container Manager list');
            $items=$found['containers']??[];
            if (!is_array($items)) throw new RuntimeException('DSM container listing has an unfamiliar response format.');
            $safe=[];
            foreach(array_slice($items,0,100) as $entry) {
                if (!is_array($entry)) continue;
                $name=(string)($entry['name']??'');
                if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/D',$name))continue;
                $safe[]=[
                    'name'=>$name,
                    'image'=>substr((string)($entry['image']??''),0,160),
                    'status'=>substr((string)($entry['status']??''),0,60)
                ];
            }
            return ['operation'=>'test','containers'=>$safe,
                'count'=>count($items),
                'message'=>'DSM administrator authorization succeeded. Container Manager returned '.count($items).' container(s).'];
        }
        $name=dsm_approval_container_name((string)($server['container_name']??''));
        $details=dsm_approval_checked($api([
            'api'=>'SYNO.Docker.Container','version'=>'1',
            'method'=>'get','name'=>json_encode($name,JSON_UNESCAPED_SLASHES)
        ]),'Container identity verification');
        if (!dsm_approval_container_matches($details,$server,$name)) {
            throw new RuntimeException('Container image or /data mount could not be verified. No operation was performed.');
        }
        dsm_approval_checked($api([
            'api'=>'SYNO.Docker.Container','version'=>'1',
            'method'=>$operation,'name'=>json_encode($name,JSON_UNESCAPED_SLASHES)
        ]),'Container '.$operation);
        return [
            'operation'=>$operation,'container'=>$name,
            'message'=>'DSM authorized the '.($operation==='start'?'start':'stop').' operation on '.$name.'. No ongoing Docker permission was granted.'
        ];
    } finally {
        if ($session!=='') {
            try {
                $send([
                    'api'=>'SYNO.API.Auth','version'=>'6','method'=>'logout',
                    'session'=>'Docker','_sid'=>$session
                ]);
            } catch(Throwable) { /* Cannot leave credentials or session in a job.
                Session expires according to DSM policy if logout cannot connect. */ }
        }
    }
}
