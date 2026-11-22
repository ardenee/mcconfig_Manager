<?php
declare(strict_types=1);
/**
 * Experimental one-request DSM Container Manager approval.
 * Authenticates only to DSM on NAS loopback, never to a supplied URL.
 * sid/CSRF are local variables and are always discarded after logout.
 * No job queue, persistent session, credential files, or Docker socket access.
 */
/**
 * Only unsuccessful responses from SYNO.API.Auth count against this limiter.
 * Wizard operations (image pull, verify, create, start) and validation failures
 * never consume login attempts. Credentials remain ephemeral.
 */
function dsm_login_rate_guard(): void {
    if (session_status()!==PHP_SESSION_ACTIVE) return;
    // Drop the legacy counter: it counted successful logins followed by failed
    // Docker operations, which could lock out an authorized administrator.
    unset($_SESSION['dsm_approval_tries'],$_SESSION['dsm_approval_since']);
    $since=(int)($_SESSION['dsm_failed_login_since_v2']??0);
    if ($since<=0||time()-$since>=900) {
        unset($_SESSION['dsm_failed_login_count_v2'],$_SESSION['dsm_failed_login_since_v2']);
        return;
    }
    if ((int)($_SESSION['dsm_failed_login_count_v2']??0)>=4) {
        $remaining=max(1,(int)ceil((900-(time()-$since))/60));
        throw new RuntimeException('Too many failed DSM login attempts. Try again in '.$remaining.' minute(s).');
    }
}
function dsm_login_rate_failure(): void {
    if (session_status()!==PHP_SESSION_ACTIVE) return;
    $since=(int)($_SESSION['dsm_failed_login_since_v2']??0);
    if ($since<=0||time()-$since>=900) {
        $_SESSION['dsm_failed_login_since_v2']=time();
        $_SESSION['dsm_failed_login_count_v2']=0;
    }
    $_SESSION['dsm_failed_login_count_v2']=(int)($_SESSION['dsm_failed_login_count_v2']??0)+1;
}
function dsm_login_rate_success(): void {
    if (session_status()!==PHP_SESSION_ACTIVE) return;
    unset($_SESSION['dsm_failed_login_count_v2'],$_SESSION['dsm_failed_login_since_v2'],
        $_SESSION['dsm_approval_tries'],$_SESSION['dsm_approval_since']);
}
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
/**
 * Verify the identity and actual /data bind mount without returning inspect data.
 * Synology may return Docker inspect details, a DSM profile or both.
 * Treat conflicting evidence as a failure, even if one section matches.
 */
function dsm_approval_container_assessment(array $details,array $server,string $name): array {
    $expected=realpath((string)($server['path']??''));
    if ($expected===false) {
        return ['verified'=>false,'name'=>'missing','image'=>'missing','mount'=>'storage unavailable'];
    }
    $sections=[[$details,0]];
    $nameFound=false;$imageFound=false;$mountFound=false;
    $wrongName=false;$wrongImage=false;$wrongMount=false;
    $imagePattern=(($server['edition']??'java')==='bedrock')
        ? '~^(?:(?:docker\.io|registry-1\.docker\.io|index\.docker\.io)/)?itzg/minecraft-bedrock-server(?::|@|$)~iD'
        : '~^(?:(?:docker\.io|registry-1\.docker\.io|index\.docker\.io)/)?itzg/minecraft-server(?::|@|$)~iD';
    $isMatch=function(string $source)use($expected):bool {
        if ($source==='' || (PHP_OS_FAMILY!=='Windows' && $source[0]!=='/'))return false;
        if (rtrim($source,'/')===$expected)return true;
        return realpath($source)===$expected;
    };
    // Only follow identity/profile wrapper keys. Never traverse environment fields.
    for ($i=0;$i<count($sections) && $i<32;$i++) {
        [$info,$depth]=$sections[$i];
        if ($depth<4) {
            foreach(['data','details','profile','container','info'] as $key) {
                if (is_array($info[$key]??null))$sections[]=[$info[$key],$depth+1];
            }
        }
        foreach(['Name','name'] as $key) {
            if (!isset($info[$key]) || !is_string($info[$key]) || $info[$key]==='')continue;
            if (ltrim($info[$key],'/')===$name)$nameFound=true;else $wrongName=true;
        }
        $images=[];
        foreach(['image','ImageName','image_name','image_ref'] as $key) {
            if (is_string($info[$key]??null))$images[]=$info[$key];
        }
        if (is_string($info['Config']['Image']??null))$images[]=$info['Config']['Image'];
        foreach ($images as $image) {
            if ($image==='')continue;
            if (preg_match($imagePattern,$image))$imageFound=true;
            elseif (!preg_match('/^(?:sha256:)?[0-9a-f]{64}$/iD',$image))$wrongImage=true;
        }
        $groups=[];
        foreach(['Mounts','mounts','volume_bindings','volumes'] as $key) {
            if (is_array($info[$key]??null))$groups[]=$info[$key];
        }
        if (is_array($info['HostConfig']['Mounts']??null))$groups[]=$info['HostConfig']['Mounts'];
        if (is_array($info['HostConfig']['Binds']??null))$groups[]=$info['HostConfig']['Binds'];
        foreach($groups as $mounts) {
            foreach($mounts as $mount) {
                $source='';$destination='';
                if (is_string($mount) && preg_match('~^(.+):(/[^:]+)(?::[^:]*)?$~D',$mount,$parts)) {
                    $source=$parts[1];$destination=$parts[2];
                } elseif(is_array($mount)) {
                    $destination=(string)($mount['Destination']??$mount['destination']??
                        $mount['Target']??$mount['target']??$mount['mount_point']??
                        $mount['container_path']??'');
                    $source=(string)($mount['Source']??$mount['source']??
                        $mount['host_volume_file']??$mount['host_path']??'');
                }
                if (rtrim($destination,'/')!=='/data')continue;
                if ($isMatch($source))$mountFound=true;else $wrongMount=true;
            }
        }
    }
    $nameStatus=$wrongName?'mismatch':($nameFound?'confirmed':'not reported');
    $imageStatus=$wrongImage?'mismatch':($imageFound?'confirmed':'not reported');
    $mountStatus=$wrongMount?'mismatch':($mountFound?'confirmed':'not reported');
    return [
        'verified'=>$nameFound&&$imageFound&&$mountFound&&!$wrongName&&!$wrongImage&&!$wrongMount,
        'name'=>$nameStatus,'image'=>$imageStatus,'mount'=>$mountStatus
    ];
}
function dsm_approval_container_matches(array $details,array $server,string $name): bool {
    return dsm_approval_container_assessment($details,$server,$name)['verified'];
}
function dsm_approval_container_failure(array $details,array $server,string $name): string {
    $check=dsm_approval_container_assessment($details,$server,$name);
    // Do not leak Docker inspect content, env values, private paths or credentials.
    return 'Container verification failed: name '.$check['name'].
        ', image '.$check['image'].', /data bind mount '.$check['mount'].'.';
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
    if (!in_array($operation,['test','start','stop','bedrock-image','bedrock-pull','bedrock-poll','bedrock-create','bedrock-verify','cleanup'],true)) {
        throw new InvalidArgumentException('Unsupported DSM one-time operation.');
    }
    if (!preg_match('/^[\w.@-]{1,64}$/D',$account) ||
        $password==='' || strlen($password)>1024) {
        throw new InvalidArgumentException('Enter a valid DSM account and password.');
    }
    if ($otp!=='' && !preg_match('/^[0-9]{6,8}$/D',$otp)) {
        throw new InvalidArgumentException('Enter a valid DSM one-time verification code.');
    }
    if (in_array($operation,['start','stop','bedrock-verify','cleanup'],true) && (!$server ||
        ($server['deployment']??'native')!=='container' ||
        !in_array(($server['install_state']??'ready'),
            $operation==='bedrock-verify'||$operation==='cleanup'?['ready','imported','unverified']:['ready','imported'],true))) {
        throw new RuntimeException('Select a registered Minecraft container; start/stop requires a verified, ready server.');
    }
    dsm_login_rate_guard();
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
        $loginReply=$send($fields);
        // Count only an explicit negative response from DSM authentication,
        // not an API transport error or any later container verification error.
        if (($loginReply['success']??null)===false) dsm_login_rate_failure();
        $login=dsm_approval_checked($loginReply,'DSM login');
        $session=(string)($login['sid']??'');
        $token=(string)($login['synotoken']??'');
        if ($session==='' || strlen($session)>512) {
            throw new RuntimeException('DSM login succeeded but no session ID was returned.');
        }
        // Login was valid even if a subsequent Docker API operation fails.
        dsm_login_rate_success();
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
                throw new RuntimeException(dsm_approval_container_failure($details,$server,$name).' Nothing was started.');
            }
            return ['operation'=>'bedrock-verify','verified'=>true,'container'=>$name,
                'message'=>'DSM verified the registered Bedrock container. Approve Start separately when ready.'];
        }
        if ($operation==='cleanup') {
            // Only manager-created Bedrock containers can be removed.
            $name=dsm_approval_container_name((string)($server['container_name']??''));
            if (($server['control_mode']??'')!=='dsm-on-demand' ||
                ($server['edition']??'')!=='bedrock' ||
                !preg_match('/^mcmanager-bedrock-[a-f0-9]{12}$/D',$name)) {
                throw new RuntimeException('Only mcmanager-created Bedrock containers can be cleaned up.');
            }
            $list=function()use($api):array {
                $result=dsm_approval_checked($api([
                    'api'=>'SYNO.Docker.Container','version'=>'1','method'=>'list',
                    'limit'=>'-1','offset'=>'0','type'=>'all'
                ]),'Container Manager list');
                $items=$result['containers']??null;
                if (!is_array($items)) throw new RuntimeException('Cannot verify DSM containers; nothing was deleted.');
                return $items;
            };
            $match=null;
            foreach($list() as $item) {
                if (is_array($item)&&ltrim((string)($item['name']??''),'/')===$name) {
                    $match=$item;break;
                }
            }
            if ($match===null) return ['operation'=>'cleanup','container_missing'=>true,
                'message'=>'The container is already absent in DSM. Its stale mcmanager entry can be removed.'];
            $details=dsm_approval_checked($api([
                'api'=>'SYNO.Docker.Container','version'=>'1','method'=>'get',
                'name'=>json_encode($name,JSON_UNESCAPED_SLASHES)
            ]),'Container identity verification');
            if (!dsm_approval_container_matches($details,$server,$name)) {
                throw new RuntimeException(dsm_approval_container_failure($details,$server,$name).' Cleanup was refused due to mismatch or missing evidence.');
            }
            $state=strtolower((string)($match['status']??$match['state']??''));
            if (str_contains($state,'running')||str_starts_with($state,'up')) {
                dsm_approval_checked($api([
                    'api'=>'SYNO.Docker.Container','version'=>'1','method'=>'stop',
                    'name'=>json_encode($name,JSON_UNESCAPED_SLASHES)
                ]),'Stop Minecraft container');
            }
            dsm_approval_checked($api([
                'api'=>'SYNO.Docker.Container','version'=>'1','method'=>'delete',
                'name'=>json_encode($name,JSON_UNESCAPED_SLASHES),
                'force'=>'false','preserve_profile'=>'false'
            ]),'Remove Minecraft container');
            foreach($list() as $item) {
                if (is_array($item)&&ltrim((string)($item['name']??''),'/')===$name) {
                    throw new RuntimeException('DSM still lists the container after deletion. Registration was preserved for review.');
                }
            }
            return ['operation'=>'cleanup','container_missing'=>false,
                'message'=>'Bedrock container removed. World files and image are separate.'];
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
            throw new RuntimeException(dsm_approval_container_failure($details,$server,$name).' Container could not be verified. No operation was performed.');
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
