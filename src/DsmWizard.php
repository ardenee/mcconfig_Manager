<?php
declare(strict_types=1);
/**
 * Browser-driven Bedrock install flow. Each HTTP request authorizes one
 * tightly allowlisted DSM operation and immediately logs out; the browser
 * holds the credentials only while the wizard is open, never on the NAS disk.
 */
function dsm_wizard_step(array $post,array &$config,?callable $send=null): array {
    $step=request_string($post,'dsm_operation',24);
    if (!in_array($step,['bedrock-image','bedrock-pull','bedrock-poll',
        'bedrock-create','bedrock-verify','bedrock-repair','start'],true)) {
        throw new InvalidArgumentException('Unsupported Bedrock wizard operation.');
    }
    $user=request_string($post,'dsm_account',64);
    $password=$post['dsm_password']??null;
    $otp=request_string($post,'dsm_otp',8);
    if (!is_string($password)||$password==='') {
        throw new InvalidArgumentException('Enter a DSM administrator password.');
    }
    $serverId='';
    $row=null;
    try {
        if ($step==='bedrock-poll') {
            $task=request_string($post,'pull_task',160);
            if ($task===''||!preg_match('/^[A-Za-z0-9_.:@\/-]{1,160}$/D',$task)) {
                throw new InvalidArgumentException('Invalid DSM download task. Retry the image check.');
            }
            $row=['task_id'=>$task];
        } elseif ($step==='bedrock-create') {
            if (!in_array(strtolower(php_uname('m')),['x86_64','amd64'],true)) {
                throw new RuntimeException('This Bedrock image requires an x86-64 NAS. This NAS is not supported.');
            }
            $row=dsm_bedrock_install_options($post);
            $serverId=$row['server_id'];
            // A network retry must not create a duplicate server or container.
            if (isset($config['servers'][$serverId])) {
                $registered=$config['servers'][$serverId];
                if (($registered['control_mode']??'')==='dsm-on-demand' &&
                    ($registered['edition']??'')==='bedrock' &&
                    ($registered['name']??'')===$row['name'] &&
                    (int)($registered['port']??0)===$row['port'] &&
                    ($registered['container_name']??'')===$row['container_name']) {
                    return ['operation'=>'bedrock-create','server_id'=>$serverId,
                        'verified'=>($registered['install_state']??'')==='ready',
                        'message'=>'This Bedrock server was already created. Continuing verification without creating a duplicate.'];
                }
                throw new RuntimeException('This Bedrock installation ID is already in use. Refresh the page before trying a different server.');
            }
            foreach(($config['servers']??[]) as $registered) {
                if (!is_array($registered))continue;
                if (($registered['edition']??'java')==='bedrock' &&
                    (int)($registered['port']??0)===$row['port']) {
                    throw new RuntimeException('UDP port '.$row['port'].' is already assigned to another Bedrock server in mcmanager. Choose a different port.');
                }
            }
        } elseif (in_array($step,['bedrock-verify','bedrock-repair','start'],true)) {
            $serverId=console_server_id(request_string($post,'server_id',64));
            $row=$config['servers'][$serverId]??null;
            if (!is_array($row) || ($row['control_mode']??'')!=='dsm-on-demand' ||
                ($row['edition']??'')!=='bedrock' || ($row['deployment']??'')!=='container') {
                throw new RuntimeException('The Bedrock server is not registered. Check Server Manager before retrying.');
            }
        }
        if ($step==='bedrock-repair' &&
            ((!function_exists('dsm_cleanup_eligible')||!dsm_cleanup_eligible($row,$serverId)) ||
             ($row['install_state']??'')!=='unverified' ||
             request_string($post,'repair_confirm',32)!=='REPLACE UNSTARTED CONTAINER' ||
             request_string($post,'bedrock_name',80)!==($row['name']??''))) {
            throw new RuntimeException('Repair requires an unverified managed Bedrock server and explicit confirmation.');
        }
        $result=dsm_approval_once($user,$password,$step,$row,$otp,$send);
        if ($step==='bedrock-create') {
            $created=$result['server']??null;
            if (!is_array($created)||($created['server_id']??'')!==$serverId) {
                throw new RuntimeException('DSM may have created the server, but its identity could not be recorded. Check Container Manager before retrying.');
            }
            unset($created['server_id'],$created['eula']);
            $created['control_mode']='dsm-on-demand';
            $created['jar']='';
            $created['worlds_path']=$created['path'].'/worlds';
            $created['install_state']=!empty($result['verified'])?'ready':'unverified';
            $config['servers'][$serverId]=$created;
            save_config($config);
            $result['server_id']=$serverId;
            unset($result['server']);
        } elseif ($step==='bedrock-verify'||$step==='bedrock-repair') {
            $config['servers'][$serverId]['install_state']='ready';
            save_config($config);
            $result['server_id']=$serverId;
        } elseif ($step==='start') {
            $result['server_id']=$serverId;
        }
        return $result;
    } finally {
        if (function_exists('sodium_memzero')) sodium_memzero($password);
        unset($password,$user,$otp);
    }
}
function dsm_wizard_error_help(string $message,string $step): array {
    $s=strtolower($message);
    if (str_contains($s,'too many')||str_contains($s,'dsm login')
        ||str_contains($s,'incorrect')||str_contains($s,'password')
        ||str_contains($s,'one-time code')) {
        return ['title'=>'DSM authorization needs attention',
            'help'=>'Check the DSM administrator username and password. If two-factor authentication is enabled, enter a fresh one-time code, then choose Retry. mcmanager has not stored your credentials.'];
    }
    if (str_contains($s,'dsm error 105')||str_contains($s,'dsm error 106')
        ||str_contains($s,'dsm error 119')) {
        return ['title'=>'DSM Container Manager permission required',
            'help'=>'Use a DSM administrator account that is permitted to access Container Manager. Check DSM Control Panel → User & Group → Applications, ensure Container Manager is available to this account, then Retry. Do not change Docker socket permissions.'];
    }
    if (($step==='bedrock-pull'||$step==='bedrock-poll')
        && (str_contains($s,'failed')||str_contains($s,'error'))) {
        return ['title'=>'Bedrock image download failed',
            'help'=>'Open DSM Container Manager → Image to inspect the download. Check NAS internet/DNS access and available disk space. Correct the issue, then select Retry. Existing Minecraft servers are unaffected.'];
    }
    if (str_contains($s,'image') && (str_contains($s,'not installed')||str_contains($s,'missing'))) {
        return ['title'=>'Bedrock image not ready',
            'help'=>'The wizard will download the image automatically. If a previous download is still running, allow it to finish; retry this step without creating another server.'];
    }
    if (str_contains($s,'port')||str_contains($s,'address already in use')) {
        return ['title'=>'Bedrock UDP port conflict',
            'help'=>$step==='start'
                ?'The server was already created with its configured UDP port. Resolve that port conflict in DSM Container Manager → Container settings, then Retry starting the server. Changing the wizard field now will not change an existing container.'
                :'The selected port is already assigned to another service. Choose another UDP port (for example 19133), then Retry. No other server will be removed.'];
    }
    if (str_contains($s,'writ')||str_contains($s,'permission')||str_contains($s,'storage')) {
        return ['title'=>'NAS storage permission required',
            'help'=>'The mcconfig Web Station service must be allowed to create folders under its managed Minecraft instances directory. Check this package directory in DSM File Station or the package permissions, then Retry. Do not grant broad access to Docker.'];
    }
    if ($step==='bedrock-repair' && str_contains($s,'never-started')) {
        return ['title'=>'Repair stopped to protect Minecraft data',
            'help'=>'DSM cannot prove the previous container was never started. The old container and anonymous volume were left intact. Inspect or back up the anonymous volume in Container Manager before considering manual replacement.'];
    }
    if (str_contains($s,'/data bind mount mismatch') && str_contains($s,'@docker/volumes/')) {
        return ['title'=>'Bedrock container has an anonymous Docker volume',
            'help'=>'The installed container was created without the required host folder mapping. Use Repair incorrect /data mount below to replace it only if DSM proves it was never started. The action requires separate confirmation and preserves the mcmanager world directory.'];
    }    if (str_contains($s,'mount')||str_contains($s,'verif')) {
        return ['title'=>'Container needs verification',
            'help'=>'Verification checks the actual Docker /data mount first. If the error lists an anonymous @docker volume, a separate guarded repair may be offered. If it lists conflicting real Docker bind paths, inspect the container volume mapping in DSM. Choose Verify container again after correcting it. Do not delete or recreate the server merely because Synology presents a second /@appdata alias.'];
    }
    if (str_contains($s,'http')||str_contains($s,'api')||str_contains($s,'connect')) {
        return ['title'=>'Container Manager communication failed',
            'help'=>'Confirm that Container Manager is running in DSM and reload this page over HTTPS. Retry the current step; the wizard preserves earlier completed work.'];
    }
    $titles=['bedrock-image'=>'Checking the Bedrock image','bedrock-pull'=>'Downloading the image',
        'bedrock-poll'=>'Waiting for image download','bedrock-create'=>'Creating the server',
        'bedrock-verify'=>'Verifying the server','start'=>'Starting the server'];
    return ['title'=>($titles[$step]??'Bedrock installation').' could not complete',
        'help'=>'The wizard stopped at this step without deleting your files or other containers. Review the reported error and choose Retry. If the container may have been created, check Container Manager before starting another installation.'];
}
