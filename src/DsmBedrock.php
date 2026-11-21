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
            ['host_volume_file'=>$root,'mount_point'=>'/data','type'=>'rw','is_directory'=>true]
        ],
        'shortcut'=>['enable_shortcut'=>false,'enable_status_page'=>false,'enable_web_page'=>false,'web_page_url'=>'']
    ];
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
