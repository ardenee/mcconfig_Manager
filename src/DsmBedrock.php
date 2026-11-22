<?php
declare(strict_types=1);
/**
 * Bedrock provisioning through DSM's one-request Container Manager API.
 * Never gives the mcconfig service account ongoing Docker socket privileges.
 * A pull is asynchronous in DSM; creating a server requires a fresh approval.
 */
function dsm_bedrock_image_name(): string {
    return 'itzg/minecraft-bedrock-server:latest';
}
function dsm_bedrock_image_reference(array $images): string {
    foreach ($images as $entry) {
        if (!is_array($entry)) continue;
        $repo=strtolower(trim((string)($entry['repository']??'')));
        $tags=(array)($entry['tags']??[]);
        if (isset($entry['tag'])) $tags[]=(string)$entry['tag'];
        // Registry prefixes are emitted differently across DSM versions.
        $validRepo=$repo==='itzg/minecraft-bedrock-server' ||
            (bool)preg_match('~^(?:docker\.io|registry-1\.docker\.io|index\.docker\.io)/itzg/minecraft-bedrock-server$~D',$repo);
        if (!$validRepo) {
            if (preg_match('~^(?:(?:docker\.io|registry-1\.docker\.io|index\.docker\.io)/)?itzg/minecraft-bedrock-server:latest$~D',$repo)) {
                return $repo;
            }
            continue;
        }
        foreach ($tags as $tag) {
            if ($tag==='latest' || $tag===$repo.':latest' ||
                $tag==='itzg/minecraft-bedrock-server:latest') return $repo.':latest';
        }
    }
    return '';
}
function dsm_bedrock_image_present(array $images): bool {
    return dsm_bedrock_image_reference($images)!=='';
}
function dsm_bedrock_image_check(callable $api): array {
    $result=dsm_approval_checked($api([
        'api'=>'SYNO.Docker.Image','version'=>'1','method'=>'list',
        'offset'=>'0','limit'=>'-1'
    ]),'Container Manager image listing');
    $images=$result['images']??null;
    if (!is_array($images)) {
        throw new RuntimeException('DSM image listing had an unexpected format. No changes were made.');
    }
    $reference=dsm_bedrock_image_reference($images);
    return ['ready'=>$reference!=='','image'=>$reference,'count'=>count($images)];
}
function dsm_bedrock_install_options(array $post): array {
    $name=server_name(request_string($post,'bedrock_name',80));
    $port=filter_var($post['bedrock_port']??'',FILTER_VALIDATE_INT,
        ['options'=>['min_range'=>1024,'max_range'=>65535]]);
    $memory=filter_var($post['bedrock_memory']??'',FILTER_VALIDATE_INT,
        ['options'=>['min_range'=>512,'max_range'=>32768]]);
    if ($port===false||$memory===false) throw new InvalidArgumentException('Choose a valid Bedrock UDP port and memory limit.');
    if (empty($post['bedrock_eula'])) throw new RuntimeException('Accept the Minecraft EULA before creating the Bedrock server.');
    $requestedId=request_string($post,'bedrock_id',12);
    $id=$requestedId!==''?console_server_id($requestedId):bin2hex(random_bytes(6));
    $root=manager_instances_root();
    if (!is_dir($root) && !mkdir($root,0700,true) && !is_dir($root)) {
        throw new RuntimeException('Unable to prepare the managed Minecraft instances directory.');
    }
    if (is_link($root)||!is_writable($root))
        throw new RuntimeException('Minecraft instances root is not writable by the mcconfig web service.');
    $real=realpath($root);
    if ($real===false) throw new RuntimeException('Cannot resolve Minecraft instances storage.');
    return [
        'server_id'=>$id,'name'=>$name,'edition'=>'bedrock','software'=>'bedrock',
        'deployment'=>'container','version'=>'latest','port'=>$port,'memory'=>$memory,
        'container_name'=>'mcmanager-bedrock-'.$id,
        'path'=>$real.DIRECTORY_SEPARATOR.$id,'eula'=>true
    ];
}
function dsm_bedrock_profile(array $server,string $imageRef='itzg/minecraft-bedrock-server:latest'): array {
    if (($server['edition']??'')!=='bedrock' ||
        ($server['deployment']??'')!=='container' ||
        !preg_match('/^mcmanager-bedrock-[a-f0-9]{12}$/D',(string)($server['container_name']??''))) {
        throw new InvalidArgumentException('Invalid managed Bedrock container identity.');
    }
    $port=(int)($server['port']??0);
    $memory=(int)($server['memory']??0);
    $root=(string)($server['path']??'');
    if ($port<1024||$port>65535||$memory<512||$memory>32768 ||
        (PHP_OS_FAMILY==='Linux' && !str_starts_with($root,'/')) ||
        !is_dir($root)||is_link($root)||realpath($root)!==$root) {
        throw new RuntimeException('Managed Bedrock port, memory or storage cannot be verified.');
    }
    if (!preg_match('~^(?:(?:docker\.io|registry-1\.docker\.io|index\.docker\.io)/)?itzg/minecraft-bedrock-server:latest$~D',$imageRef)) {
        throw new InvalidArgumentException('Unsupported Bedrock image reference.');
    }
    return [
        'version'=>2,
        'name'=>$server['container_name'],
        'image'=>$imageRef,
        'enabled'=>false,
        'privileged'=>false,
        'use_host_network'=>false,
        'network_mode'=>'bridge',
        'network'=>[['name'=>'bridge','driver'=>'bridge']],
        'cpu_priority'=>50,
        'memory_limit'=>$memory*1048576,
        'enable_restart_policy'=>false,
        'enable_publish_all_ports'=>false,
        'env_variables'=>[
            ['key'=>'EULA','value'=>'TRUE'],
        ],
        'port_bindings'=>[
            ['container_port'=>19132,'host_port'=>$port,'type'=>'udp']
        ],
        'volume_bindings'=>[
            ['host_absolute_path'=>$root,'mount_point'=>'/data','type'=>'rw']
        ],
        'shortcut'=>['enable_shortcut'=>false,'enable_status_page'=>false,'enable_web_page'=>false,'web_page_url'=>'']
    ];
}
/**
 * Replace only an unverified, never-started mcmanager Bedrock container that
 * accidentally received Docker's anonymous /data volume. The admin explicitly
 * confirms the action. The server folder is retained and reused.
 *
 * If DSM cannot prove container identity, image, Docker volume source and the
 * "created, never started" state, refuse the replacement. This operation never
 * removes worlds, Docker images or volumes explicitly.
 */
function dsm_bedrock_repair_api_action(callable $api,array $server): array {
    $name=(string)($server['container_name']??'');
    if (($server['control_mode']??'')!=='dsm-on-demand' ||
        ($server['install_state']??'')!=='unverified' ||
        ($server['edition']??'')!=='bedrock' ||
        ($server['deployment']??'')!=='container' ||
        !preg_match('/^mcmanager-bedrock-([a-f0-9]{12})$/D',$name,$matches)) {
        throw new RuntimeException('Repair is restricted to an unverified mcmanager-created Bedrock server.');
    }
    $base=realpath(manager_instances_root());
    $folder=realpath((string)($server['path']??''));
    if (!$base||!$folder||$folder!==$base.DIRECTORY_SEPARATOR.$matches[1]||
        is_link($folder)||!is_dir($folder)) {
        throw new RuntimeException('Managed server storage cannot be verified; repair has not changed the container.');
    }
    $installed=dsm_bedrock_image_check($api);
    if (!$installed['ready']) {
        throw new RuntimeException('The Bedrock image is missing; repair has made no changes.');
    }
    // A valid replacement profile is computed before touching the old container.
    $profile=dsm_bedrock_profile($server,$installed['image']);
    $list=function()use($api):array {
        $data=dsm_approval_checked($api([
            'api'=>'SYNO.Docker.Container','version'=>'1','method'=>'list',
            'limit'=>'-1','offset'=>'0','type'=>'all'
        ]),'Bedrock repair container list');
        if (!is_array($data['containers']??null))throw new RuntimeException(
            'DSM did not return a container list; repair was refused.');
        return $data['containers'];
    };
    $entry=null;
    foreach($list() as $item) {
        if (is_array($item)&&ltrim((string)($item['name']??''),'/')===$name) {
            $entry=$item;break;
        }
    }
    if ($entry!==null) {
        $details=dsm_approval_checked($api([
            'api'=>'SYNO.Docker.Container','version'=>'1','method'=>'get',
            'name'=>json_encode($name,JSON_THROW_ON_ERROR)
        ]),'Bedrock repair inspection');
        $assessment=dsm_approval_container_assessment($details,$server,$name);
        if ($assessment['verified']) {
            return ['operation'=>'bedrock-repair','verified'=>true,
                'message'=>'The existing container already has the correct bind mount; nothing was replaced.'];
        }
        if ($assessment['name']!=='confirmed'||$assessment['image']!=='confirmed'||
            $assessment['mount']!=='mismatch') {
            throw new RuntimeException('Container identity, image or mount cannot be proved. Repair refused; nothing was deleted.');
        }
        // Only an actual Docker volume at /data is eligible. Never replace an
        // existing bind mount that may be deliberate or hold user data.
        $sections=[$details];
        $volume=false;$otherDataMount=false;$state='';
        $everStarted=false;$noStartTimestamp=false;
        for ($idx=0;$idx<count($sections) && $idx<32;$idx++) {
            $section=$sections[$idx];
            if (!is_array($section)) continue;
            if ($idx<16)foreach(['details','container','info'] as $key) {
                if (is_array($section[$key]??null))$sections[]=$section[$key];
            }
            $status=$section['State']['Status']??$section['state']['status']??
                $section['State']['status']??'';
            if (is_string($status)&&$status!=='')$state=strtolower($status);
            $runtime=$section['State']??$section['state']??null;
            if (is_array($runtime)) {
                if (($runtime['Running']??$runtime['running']??false)===true)
                    $everStarted=true;
                $started=$runtime['StartedAt']??$runtime['started_at']??null;
                if (is_string($started) && $started!=='') {
                    if (preg_match('/^(?:0001-01-01|1970-01-01)T00:00:00(?:\\.0+)?Z$/D',$started))
                        $noStartTimestamp=true;
                    else $everStarted=true;
                }
            }
            foreach (($section['Mounts']??[]) as $mount) {
                if (!is_array($mount) ||
                    (($mount['Destination']??$mount['Target']??'')!=='/data'))continue;
                $source=(string)($mount['Source']??'');
                $type=strtolower((string)($mount['Type']??''));
                if (($type==='volume'||$type==='') &&
                    preg_match('~^/volume[0-9]+/@docker/volumes/[a-f0-9]{64}/_data/?$~iD',$source)) {
                    $volume=true;
                } else $otherDataMount=true;
            }
        }
        $listedState=strtolower((string)($entry['status']??$entry['state']??''));
        // 'created' is Docker's never-started state. "exited"/"stopped" does
        // not prove the volume was unused; those must be recovered manually.
        if ($everStarted || ($state!=='created' && $listedState!=='created' && !$noStartTimestamp)) {
            throw new RuntimeException('Container has not been proven never-started (DSM state: '.
                substr($state?:$listedState?:'unknown',0,32).'). Repair refused to protect possible world data.');
        }
        if (!$volume||$otherDataMount) {
            throw new RuntimeException('The old /data mount is not confirmed to be an anonymous Docker volume. No replacement was made.');
        }
        dsm_approval_checked($api([
            'api'=>'SYNO.Docker.Container','version'=>'1','method'=>'delete',
            'name'=>json_encode($name,JSON_THROW_ON_ERROR),
            'force'=>'false','preserve_profile'=>'false'
        ]),'Remove never-started malformed Bedrock container');
        foreach($list() as $item) {
            if (is_array($item)&&ltrim((string)($item['name']??''),'/')===$name) {
                throw new RuntimeException('DSM still lists the old container. Nothing was recreated; retry after it disappears.');
            }
        }
    }
    // Also recovers a prior repair interrupted between delete and create.
    dsm_approval_checked($api([
        'api'=>'SYNO.Docker.Container','version'=>'1','method'=>'create',
        'is_run_instantly'=>'false',
        'profile'=>json_encode($profile,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)
    ]),'Create replacement with verified Bedrock bind mount');
    $created=dsm_approval_checked($api([
        'api'=>'SYNO.Docker.Container','version'=>'1','method'=>'get',
        'name'=>json_encode($name,JSON_THROW_ON_ERROR)
    ]),'Inspect replacement Bedrock container');
    if (!dsm_approval_container_matches($created,$server,$name)) {
        throw new RuntimeException('Replacement was created but DSM has not confirmed its /data bind mount. It was left stopped, and no world files were removed. '.dsm_approval_container_failure($created,$server,$name));
    }
    return ['operation'=>'bedrock-repair','verified'=>true,
        'message'=>'Recreated the never-started Bedrock container with the correct persistent /data bind mount. Existing managed files were retained.'];
}

function dsm_bedrock_api_action(callable $api,string $operation,?array $options): array {
    if ($operation==='bedrock-image') {
        $check=dsm_bedrock_image_check($api);
        return ['operation'=>'bedrock-image','image_ready'=>$check['ready'],
            'message'=>$check['ready']
                ?'Bedrock image is installed and ready for server creation.'
                :'Bedrock image is not installed yet. Authorize Download Bedrock image first.'];
    }
    if ($operation==='bedrock-poll') {
        $task=(string)($options['task_id']??'');
        if (!preg_match('/^[A-Za-z0-9_.:@\/-]{1,160}$/D',$task)) {
            throw new InvalidArgumentException('The image download task is not valid.');
        }
        $last=[];
        // Keep each HTTP request below DSM Web Station's default timeout.
        for($attempt=0;$attempt<6;$attempt++) {
            $last=dsm_approval_checked($api([
                'api'=>'SYNO.Docker.Image','version'=>'1','method'=>'pull_status',
                'task_id'=>json_encode($task,JSON_THROW_ON_ERROR)
            ]),'Bedrock download status');
            if (!empty($last['finished'])) break;
            if ($attempt<5) usleep(2100000);
        }
        $finished=!empty($last['finished']);
        $progress=$last['progress']??$last['percent']??null;
        $percent=is_numeric($progress)
            ?max(0,min(100,(int)$progress)):null;
        // Do not assume "finished" guarantees a usable image.
        return ['operation'=>'bedrock-poll','finished'=>$finished,'percent'=>$percent,
            'message'=>$finished?'Download task finished; checking the image.':
                'Container Manager is downloading the Bedrock image. Progress will update here.'];
    }
    if ($operation==='bedrock-pull') {
        $check=dsm_bedrock_image_check($api);
        if ($check['ready']) return [
            'operation'=>'bedrock-pull','image_ready'=>true,
            'message'=>'Bedrock image is already installed; it was not downloaded again.'
        ];
        // Initiate a bounded, named image pull. The task continues inside DSM
        // after the admin session is destroyed. No password goes into its args.
        $started=dsm_approval_checked($api([
            'api'=>'SYNO.Docker.Image','version'=>'1','method'=>'pull_start',
            'repository'=>json_encode('itzg/minecraft-bedrock-server',JSON_THROW_ON_ERROR),
            'tag'=>json_encode('latest',JSON_THROW_ON_ERROR),
            'registry'=>json_encode('https://registry-1.docker.io',JSON_THROW_ON_ERROR),
            'username'=>json_encode('',JSON_THROW_ON_ERROR),
            'password'=>json_encode('',JSON_THROW_ON_ERROR),
        ]),'Bedrock image download');
        $task=(string)($started['task_id']??'');
        if ($task==='' || strlen($task)>160) {
            throw new RuntimeException('Container Manager did not confirm the image download task; inspect the Container Manager Image tab.');
        }
        return ['operation'=>'bedrock-pull','image_ready'=>false,'task_id'=>$task,
            'message'=>'Bedrock image download started in Container Manager.'];
    }
    if ($operation!=='bedrock-create'||!is_array($options)) {
        throw new InvalidArgumentException('Unsupported Bedrock DSM operation.');
    }
    $installedImage=dsm_bedrock_image_check($api);
    if (!$installedImage['ready']) {
        throw new RuntimeException('Bedrock image is not installed. Download it first, then repeat creation after it appears in Container Manager.');
    }
    $list=dsm_approval_checked($api([
        'api'=>'SYNO.Docker.Container','version'=>'1','method'=>'list',
        'limit'=>'-1','offset'=>'0','type'=>'all'
    ]),'Existing container check');
    $existing=$list['containers']??null;
    if (!is_array($existing))throw new RuntimeException('Container list was not understood. Creation refused.');
    foreach($existing as $item) {
        if (is_array($item) &&
            (($item['name']??'')===$options['container_name'])) {
            throw new RuntimeException('This Minecraft container name already exists.');
        }
    }
    $root=$options['path'];
    if (file_exists($root)||is_link($root))
        throw new RuntimeException('The new Minecraft server storage folder is already in use.');
    if (!mkdir($root,0700))throw new RuntimeException('Unable to create Bedrock server storage folder.');
    $created=false;
    try {
        if (!mkdir($root.'/worlds',0700)) throw new RuntimeException('Cannot create persistent Bedrock worlds folder.');
        $profile=dsm_bedrock_profile($options,$installedImage['image']);
        dsm_approval_checked($api([
            'api'=>'SYNO.Docker.Container','version'=>'1','method'=>'create',
            'is_run_instantly'=>'false',
            'profile'=>json_encode($profile,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)
        ]),'Bedrock container creation');
        $created=true;
        // Non-mutating post-create verification. Do not start until approved.
        try {
            $found=dsm_approval_checked($api([
                'api'=>'SYNO.Docker.Container','version'=>'1','method'=>'get',
                'name'=>json_encode($options['container_name'],JSON_THROW_ON_ERROR)
            ]),'Bedrock container verification');
            $verified=dsm_approval_container_matches($found,$options,$options['container_name']);
        } catch(Throwable) {$verified=false;}
        return [
            'operation'=>'bedrock-create',
            'container'=>$options['container_name'],
            'server'=>$options,
            'verified'=>$verified,
            'message'=>$verified
                ?'Bedrock container created, configuration verified and left stopped. Approve Start separately.'
                :'DSM accepted container creation but could not confirm its profile. The server is registered as needing verification. Review it in Container Manager before starting.'
        ];
    } finally {
        if (!$created && is_dir($root) && !is_link($root)) {
            if (is_dir($root.'/worlds') && !is_link($root.'/worlds')) @rmdir($root.'/worlds');
            @rmdir($root);
        }
    }
}
