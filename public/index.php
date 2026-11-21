<?php
declare(strict_types=1);
if (PHP_VERSION_ID < 80200) { http_response_code(500); exit('mcconfig requires PHP 8.2 or newer.'); }
require dirname(__DIR__) . '/src/App.php';
require dirname(__DIR__) . '/src/Manager.php';
require dirname(__DIR__) . '/src/Console.php';
require dirname(__DIR__) . '/src/Uploads.php';
require dirname(__DIR__) . '/src/Plugins.php';
require dirname(__DIR__) . '/src/ServerJar.php';
require dirname(__DIR__) . '/src/Startup.php';
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ini_set('session.cookie_secure', '1');
session_name('mcconfig_session');
// Keep session storage private and writable across DSM Web Station PHP profiles.
$privateSessions = data_dir() . DIRECTORY_SEPARATOR . 'sessions';
if ((!is_dir($privateSessions) && !@mkdir($privateSessions, 0700, true)) || !is_writable($privateSessions)) {
    http_response_code(503);
    exit('mcconfig cannot access its private session directory. Check PHP-FPM permissions and the Web Station open_basedir setting.');
}
session_save_path($privateSessions);
session_start();
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; form-action 'self'; base-uri 'self'; frame-ancestors 'none'");
$config = app_config();
$installed = isset($config['password_hash']) && is_string($config['password_hash']);
$authenticated = $installed && ($_SESSION['authenticated'] ?? false) === true;
$tab = request_string($_GET, 'tab', 32);
$tabs = ['manager'=>'Server Manager', 'overview'=>'Overview', 'properties'=>'Server properties', 'players'=>'Players & access', 'files'=>'Config files', 'worlds'=>'Worlds', 'logs'=>'Server logs', 'plugins'=>'Plugins', 'console'=>'Console', 'jar'=>'JAR management', 'settings'=>'Settings', 'about'=>'About'];
if (!isset($tabs[$tab])) $tab = 'overview';
$message = (string)($_SESSION['flash'] ?? '');
unset($_SESSION['flash']);
$error = '';
$selected = '';
$server = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = '';
    try {
        check_csrf();
        $action = request_string($_POST, 'action', 48);
        if (!$installed && $action === 'setup') {
            $password = request_string($_POST, 'password', 512);
            if (strlen($password) < 12) throw new InvalidArgumentException('Use an administrator password of at least 12 characters.');
            if ($password !== request_string($_POST, 'confirm', 512)) throw new InvalidArgumentException('Passwords do not match.');
            $config = ['password_hash'=>password_hash($password, PASSWORD_DEFAULT), 'servers'=>[]];
            save_config($config);
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            $_SESSION['flash'] = 'Administrator created. Add a Minecraft server under Settings.';
            redirect('settings');
        }
        if ($installed && $action === 'login') {
            $password = request_string($_POST, 'password', 512);
            $tries = (int)($_SESSION['login_tries'] ?? 0);
            $since = (int)($_SESSION['login_since'] ?? 0);
            if ($tries >= 8 && time() - $since < 900) throw new RuntimeException('Too many attempts. Try again in 15 minutes.');
            if (!password_verify($password, $config['password_hash'])) {
                if ($tries === 0 || time() - $since >= 900) { $tries = 0; $since = time(); }
                $_SESSION['login_tries'] = $tries + 1;
                $_SESSION['login_since'] = $since;
                throw new RuntimeException('Incorrect password.');
            }
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            $_SESSION['login_tries'] = 0;
            redirect();
        }
        if (!$authenticated) throw new RuntimeException('Log in to continue.');
        if ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            redirect();
        }
        if ($action === 'manager_diagnostics' || $action === 'manager_discover') {
            if (!manager_online()) throw new RuntimeException('Local management worker is offline.');
            $job=manager_enqueue($action === 'manager_diagnostics'?'diagnostics':'discover');
            redirect('manager','&job='.$job);
        }
        if ($action === 'manager_install_java') {
            if (!manager_online()) throw new RuntimeException('Local management worker is offline.');
            $major=(int)request_string($_POST,'major',2);
            if (!in_array($major,[17,21,25],true)) throw new InvalidArgumentException('Choose Java 17, 21 or 25.');
            $source=request_string($_POST,'source',12);
            if (!in_array($source,['catalog','url','upload'],true)) throw new InvalidArgumentException('Invalid Java installation method.');
            $options=['major'=>$major,'source'=>$source];
            if ($source==='url') {
                $options['url']=request_string($_POST,'url',1500);
                if (!str_starts_with($options['url'],'https://')) throw new InvalidArgumentException('Use an HTTPS URL from official Adoptium Temurin.');
            }
            if ($source==='upload') {
                $uploaded=manager_upload_file($_FILES['java_archive']??[], 'java');
                $options['upload_id']=$uploaded['upload_id'];
                $options['sha256']=$uploaded['sha256'];
            }
            $job=manager_enqueue('install_java',$options);
            redirect('manager','&job='.$job);
        }
        if ($action==='server_jar_install') {
            if (!manager_online()) throw new RuntimeException('Start the management worker to install a Minecraft server JAR.');
            $id=console_server_id(request_string($_POST,'server_id',64));
            $row=$config['servers'][$id]??null;
            if (!is_array($row) || ($row['edition']??'java')!=='java' ||
                ($row['deployment']??'native')!=='native' ||
                in_array(($row['install_state']??'ready'),['pending','failed'],true)) {
                throw new RuntimeException('Select a registered native Java destination server.');
            }
            if (empty($_POST['stopped'])) throw new RuntimeException('Confirm the selected server is stopped.');
            $source=request_string($_POST,'source',16);
            if (!in_array($source,['catalog','url','upload'],true)) throw new InvalidArgumentException('Invalid JAR installation method.');
            $version=request_string($_POST,'version',48);
            if ($version!=='' && $version!=='latest' && !jar_version_valid($version)) {
                throw new InvalidArgumentException('Choose a valid Minecraft release identifier.');
            }
            $params=['source'=>$source,'version'=>$version,'confirmed_stopped'=>true];
            if ($source==='upload') {
                if ($version==='latest') throw new InvalidArgumentException('Provide an actual Minecraft version, or leave the version blank for JAR inspection.');
                $upload=manager_upload_file($_FILES['jar_file']??[],'server_jar');
                $params['upload_id']=$upload['upload_id'];
                $params['checksum']=$upload['sha256'];
            }
            if ($source==='url') {
                if ($version==='latest') throw new InvalidArgumentException('Specify a Minecraft release for a direct URL.');
                $params['url']=request_string($_POST,'url',1600);
                $params['checksum']=strtolower(request_string($_POST,'checksum',64));
                if (!preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D',$params['checksum'])) {
                    throw new InvalidArgumentException('Provide the official SHA-1 or SHA-256 checksum for this server JAR.');
                }
            }
            $job=manager_enqueue('install_server_jar',$params,$id);
            $_SESSION['server_id']=$id;
            redirect('manager','&job='.$job);
        }
        if (in_array($action,['plugin_upload','plugin_url','plugin_remove'],true)) {
            if (!manager_online()) throw new RuntimeException('Plugin management requires the local worker.');
            $serverId=console_server_id(request_string($_POST,'server_id',64));
            if (!isset($config['servers'][$serverId]) || !plugin_supported($config['servers'][$serverId])) {
                throw new RuntimeException('The selected Minecraft server does not support Bukkit/Paper plugins.');
            }
            $params=[];
            if ($action==='plugin_upload') {
                $upload=manager_upload_file($_FILES['plugin_jar']??[], 'plugin');
                $params=['source'=>'upload','filename'=>plugin_jar_name($upload['filename']),
                    'upload_id'=>$upload['upload_id'],'sha256'=>$upload['sha256']];
            } elseif ($action==='plugin_url') {
                $params=['source'=>'url',
                    'filename'=>plugin_jar_name(request_string($_POST,'filename',116)),
                    'url'=>request_string($_POST,'url',1200),
                    'sha256'=>strtolower(request_string($_POST,'sha256',64))];
                if (!preg_match('/^[a-f0-9]{64}$/D',$params['sha256'])) {
                    throw new InvalidArgumentException('A complete SHA-256 checksum is required for plugin URL downloads.');
                }
            } else {
                $params=['filename'=>plugin_jar_name(request_string($_POST,'filename',116))];
            }
            $job=manager_enqueue($action==='plugin_remove'?'remove_plugin':'install_plugin',$params,$serverId);
            $_SESSION['server_id']=$serverId;
            redirect('manager','&job='.$job);
        }
        if ($action === 'console_send') {
            if (!manager_online()) throw new RuntimeException('Console is unavailable while the management worker is offline.');
            $serverId=console_server_id(request_string($_POST,'server_id',64));
            if (!isset($config['servers'][$serverId])) throw new InvalidArgumentException('Server is not registered.');
            if (($config['servers'][$serverId]['install_state']??'ready')!=='ready') throw new RuntimeException('Server is not ready for console commands.');
            $command=console_command_input(request_string($_POST,'command',500));
            $job=manager_enqueue('console_command',['command'=>$command],$serverId);
            $_SESSION['server_id']=$serverId;
            if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH']??''))==='xmlhttprequest') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['job'=>$job,'state'=>'queued'],JSON_THROW_ON_ERROR);
                exit;
            }
            redirect('console','&job='.$job);
        }
        if ($action === 'manager_unregister_failed') {
            $serverId=console_server_id(request_string($_POST,'server_id',64));
            if (!isset($config['servers'][$serverId]) ||
                ($config['servers'][$serverId]['install_state']??'')!=='failed') {
                throw new RuntimeException('Only failed provisioning entries may be unregistered this way.');
            }
            unset($config['servers'][$serverId]);
            save_config($config);
            $_SESSION['flash']='Failed server registration removed. Existing partial server files and containers have not been deleted.';
            redirect('manager');
        }
        if ($action === 'manager_cancel_job') {
            $job=request_string($_POST,'job_id',24);
            if (!preg_match('/^[a-f0-9]{24}$/D',$job)) throw new InvalidArgumentException('Invalid queued job.');
            if (!manager_cancel_queued_job($job)) {
                throw new RuntimeException('Job is already running, completed or unavailable and cannot be cancelled here.');
            }
            $_SESSION['flash']='The queued job was cancelled without execution.';
            redirect('manager');
        }
        if ($action === 'manager_startup') {
            if (!manager_online()) throw new RuntimeException('Management worker is offline.');
            $serverId=console_server_id(request_string($_POST,'server_id',64));
            $row=$config['servers'][$serverId]??null;
            if (!is_array($row) || ($row['edition']??'java')!=='java' ||
                ($row['deployment']??'native')!=='native' ||
                in_array(($row['install_state']??'ready'),['pending','failed'],true)) {
                throw new RuntimeException('Select a registered, installed native Java server.');
            }
            $values=startup_settings($_POST,(int)($row['memory']??2048));
            $job=manager_enqueue('configure_startup',$values,$serverId);
            $_SESSION['server_id']=$serverId;
            redirect('manager','&job='.$job);
        }
        if ($action === 'manager_backup_retention') {
            $serverId=request_string($_POST,'server_id',64);
            if (!isset($config['servers'][$serverId])) throw new RuntimeException('Unknown server.');
            $keep=filter_var($_POST['backup_keep']??'',FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>3,'max_range'=>30]]);
            if ($keep===false) throw new InvalidArgumentException('Keep between 3 and 30 backup snapshots.');
            $config['servers'][$serverId]['backup_keep']=$keep;
            save_config($config);
            $_SESSION['flash']='Backup retention updated for the selected server.';
            redirect('manager');
        }
        if ($action === 'manager_action') {
            if (!manager_online()) throw new RuntimeException('Local management worker is offline.');
            $job=manager_form_job($_POST,$config);
            redirect('manager','&job='.$job);
        }
        if ($action === 'manager_import') {
            $name=server_name(request_string($_POST,'name',80));
            $root=valid_root(request_string($_POST,'path',2048));
            $edition=request_string($_POST,'edition',12);
            $deployment=request_string($_POST,'deployment',16);
            $software=request_string($_POST,'software',20);
            $containerName=request_string($_POST,'container_name',80);
            if (!in_array($edition,['java','bedrock'],true) || !in_array($deployment,['native','container'],true)) {
                throw new InvalidArgumentException('Invalid discovered server.');
            }
            foreach (($config['servers']??[]) as $existing) {
                if (isset($existing['path']) && realpath((string)$existing['path'])===realpath($root)) {
                    throw new RuntimeException('This server directory is already registered.');
                }
            }
            $id=bin2hex(random_bytes(6));
            $config['servers'][$id]=['name'=>$name,'path'=>$root,'edition'=>$edition,
                'deployment'=>$deployment,'software'=>$software,'jar'=>'server.jar','worlds_path'=>'',
                'container_name'=>$containerName,'install_state'=>'ready'];
            save_config($config);
            $_SESSION['server_id']=$id;
            $job=manager_enqueue('adopt',['server'=>$config['servers'][$id]],$id);
            redirect('manager','&job='.$job);
        }
        if ($action === 'manager_install') {
            if (!manager_online()) throw new RuntimeException('Local management worker is offline.');
            $options=manager_install_input($_POST);
            foreach (($config['servers']??[]) as $existing) {
                if (($existing['install_state']??'ready')!=='failed' &&
                    ($existing['port']??null)===$options['port'] &&
                    ($existing['edition']??'java')===$options['edition']) {
                    throw new RuntimeException('A registered server is already using this edition and port.');
                }
            }
            $id=bin2hex(random_bytes(6));
            $root=manager_instances_root();
            $path=rtrim($root,'/\\').DIRECTORY_SEPARATOR.$id;
            $config['servers'][$id]=$options+['path'=>$path,'jar'=>'server.jar',
                'worlds_path'=>'','install_state'=>'pending'];
            save_config($config);
            $_SESSION['server_id']=$id;
            $job=manager_enqueue('install',$options+['eula'=>'1'],$id);
            redirect('manager','&job='.$job);
        }
        if ($action === 'add_server') {
            $name = server_name(request_string($_POST, 'name', 80));
            $root = valid_root(request_string($_POST, 'path', 2048));
            $jar = request_string($_POST, 'jar', 128);
            $edition = request_string($_POST, 'edition', 20);
            $deployment = request_string($_POST, 'deployment', 20);
            $software = request_string($_POST, 'software', 20);
            $containerName = request_string($_POST, 'container_name', 80);
            if (!preg_match('/^[A-Za-z0-9_.-]{0,80}$/D', $containerName)) throw new InvalidArgumentException('Invalid Docker container name.');
            if (!in_array($edition, ['java', 'bedrock'], true)) throw new InvalidArgumentException('Select Java or Bedrock edition.');
            if (!in_array($deployment, ['native', 'container'], true)) throw new InvalidArgumentException('Select a native or container deployment.');
            if ($edition === 'java' && $jar !== '' && !preg_match('/^[A-Za-z0-9._-]+\.jar$/iD', $jar)) throw new InvalidArgumentException('Use a simple .jar filename, not a path.');
            if ($edition === 'bedrock') $jar = '';
            $worldsPath = request_string($_POST, 'worlds_path', 2048);
            if ($edition === 'bedrock') {
                $worldsPath = valid_root($worldsPath !== '' ? $worldsPath : $root . DIRECTORY_SEPARATOR . 'worlds');
            } else $worldsPath = '';
            $id = bin2hex(random_bytes(6));
            $config['servers'][$id] = ['name'=>$name, 'path'=>$root, 'jar'=>$jar, 'edition'=>$edition, 'deployment'=>$deployment, 'worlds_path'=>$worldsPath, 'software'=>$software, 'container_name'=>$containerName, 'install_state'=>'ready'];
            if ($edition==='java') {
                $identified=jar_detect_version($config['servers'][$id]);
                if ($identified['version']!=='') {
                    $config['servers'][$id]['version']=$identified['version'];
                    $config['servers'][$id]['version_source']=$identified['source'];
                }
            }
            save_config($config);
            $_SESSION['server_id'] = $id;
            $_SESSION['flash'] = 'Server added.';
            redirect('settings');
        }
        if ($action === 'remove_server') {
            $id = request_string($_POST, 'server_id', 64);
            if (!isset($config['servers'][$id])) throw new RuntimeException('Unknown server.');
            $root=(string)($config['servers'][$id]['path']??'');
            if (is_file($root.'/.mcmanager.json')) throw new RuntimeException('This server is managed by the worker. Use Server Manager > Uninstall to stop and unregister it safely.');
            unset($config['servers'][$id]);
            save_config($config);
            unset($_SESSION['server_id']);
            $_SESSION['flash'] = 'Server unregistered from mcmanager; Minecraft files were not deleted.';
            redirect('settings');
        }
        if ($action === 'password_change') {
            if (!password_verify(request_string($_POST, 'current', 512), $config['password_hash'])) throw new RuntimeException('Current password is incorrect.');
            $password = request_string($_POST, 'password', 512);
            if (strlen($password) < 12 || $password !== request_string($_POST, 'confirm', 512)) throw new InvalidArgumentException('New passwords must match and have at least 12 characters.');
            $config['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            save_config($config);
            $_SESSION['flash'] = 'Administrator password updated.';
            redirect('settings');
        }
        $id = request_string($_POST, 'server_id', 64);
        $server = server_from($config, $id);
        $_SESSION['server_id'] = $id;
        if ($action === 'properties_create') {
            if (empty($_POST['stopped'])) throw new RuntimeException('Confirm this Minecraft server is stopped before creating configuration.');
            $live=manager_running_state($id)['state']??'unknown';
            if (manager_online() && in_array($live,['running','running-external','running-uncertain','starting'],true)) {
                throw new RuntimeException('The selected Minecraft server appears to be running. Stop it before creating configuration.');
            }
            properties_create_basic($server);
            $_SESSION['flash']='Basic server.properties created for '.$server['name'].'. Review settings before starting the server.';
            redirect('properties','&server='.rawurlencode($id));
        }
        if ($action === 'properties_save') {
            $posted = $_POST['props'] ?? [];
            if (!is_array($posted)) throw new InvalidArgumentException('Invalid properties form.');
            properties_save($server, $posted, request_string($_POST, 'new_key', 80), request_string($_POST, 'new_value', 4096));
            $_SESSION['flash'] = 'Properties saved. Restart the Minecraft server to apply changes.';
            redirect('properties');
        }
        if ($action === 'select_world') {
            $name = request_string($_POST, 'world', 255);
            if (!in_array($name, list_worlds($server), true)) throw new InvalidArgumentException('World directory not found.');
            $properties = properties_read($server);
            properties_save($server, ['level-name'=>$name], array_key_exists('level-name', $properties) ? '' : 'level-name', $name);
            $_SESSION['flash'] = 'World selection saved. Restart Minecraft to use it.';
            redirect('worlds');
        }
        if ($action === 'player_add' || $action === 'player_remove') {
            $type = request_string($_POST, 'type', 32);
            if (is_bedrock($server)) bedrock_update($server, $type, $action === 'player_add' ? 'add' : 'remove', $_POST);
            else player_update($server, $type, $action === 'player_add' ? 'add' : 'remove', $_POST);
            $_SESSION['flash'] = 'Player list updated. Your Minecraft server may need a reload or restart.';
            redirect('players');
        }
        if ($action === 'jar_upload') {
            // Preserve the original generic-web-host upload workflow. DSM package
            // installs must use the supervised worker instead.
            if (manager_online() || is_dir('/var/packages/mcconfig') || is_container($server)) {
                throw new RuntimeException('Use the supervised JAR management installer for this server.');
            }
            jar_upload($server,$_FILES['jar_file']??[],!empty($_POST['stopped']));
            $info=jar_detect_version($server);
            if ($info['version']!=='') {
                $config['servers'][$id]['version']=$info['version'];
                $config['servers'][$id]['version_source']=$info['source'];
            } else {
                $manual=request_string($_POST,'version',48);
                if (jar_version_valid($manual)) {
                    $config['servers'][$id]['version']=$manual;
                    $config['servers'][$id]['version_source']='administrator supplied (not independently verified)';
                }
            }
            $config['servers'][$id]['jar']=trim((string)($server['jar']??'')) ?: 'server.jar';
            save_config($config);
            if (!is_file($server['path'].'/server.properties')) {
                try {properties_create_basic($server);} catch(Throwable) {}
            }
            $_SESSION['flash']='JAR uploaded for '.$server['name'].'; previous JAR backed up if present. Verify the Minecraft version and Java runtime before starting.';
            redirect('jar','&server='.rawurlencode($id));
        }
        if ($action === 'save_text') {
            $body = $_POST['content'] ?? null;
            if (!is_string($body)) throw new InvalidArgumentException('Invalid editor content.');
            $name = request_string($_POST, 'file_key', 80);
            save_editable_text($server, $name, $body);
            $_SESSION['flash'] = 'Configuration file saved with a backup.';
            redirect('files', '&file=' . rawurlencode($name));
        }
        if ($action === 'world_upload') {
            if (!isset($_POST['stopped'])) throw new InvalidArgumentException('Confirm the server is stopped before importing worlds.');
            $name = world_archive_name(request_string($_POST, 'world_name', 80));
            $upload = $_FILES['world_zip'] ?? [];
            if (($upload['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($upload['tmp_name'] ?? '')))
                throw new RuntimeException('World ZIP upload failed; check PHP upload limits.');
            if (($upload['size'] ?? 0) > 536870912) throw new InvalidArgumentException('World ZIP is too large (512 MiB upload limit).');
            import_world_archive($server, $name, (string)$upload['tmp_name']);
            $_SESSION['flash'] = 'World imported. Select it and restart Minecraft when ready.';
            redirect('worlds');
        }
        throw new InvalidArgumentException('Unsupported action.');
    } catch (Throwable $e) {
        $error = $e->getMessage();
        // Do not expose filesystem details in the login screen.
        if (!$authenticated && $installed && $action !== 'login') $error = 'Please log in.';
        if ($action==='console_send' && strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH']??''))==='xmlhttprequest') {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error'=>$error],JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
    }
}

if ($authenticated && $tab==='manager') {
    $changed=false;
    foreach (manager_recent_jobs(80) as $job) {
        $serverId=(string)($job['server_id']??'');
        $operation=$job['operation']??'';
        $state=$job['state']??'';
        if ($operation==='install' && isset($config['servers'][$serverId]) &&
            ($config['servers'][$serverId]['install_state']??'')==='pending' &&
            in_array($state,['succeeded','failed'],true)) {
            if ($state==='succeeded') {
                $result=$job['result']??[];
                $config['servers'][$serverId]['install_state']='ready';
                $config['servers'][$serverId]['version']=$result['version']??'unknown';
                $config['servers'][$serverId]['jar']='server.jar';
            } else $config['servers'][$serverId]['install_state']='failed';
            $changed=true;
        }
        if ($operation==='install_server_jar' && $state==='succeeded' &&
            isset($config['servers'][$serverId])) {
            $result=$job['result']??[];
            if (is_string($result['version']??null) && jar_version_valid($result['version']) &&
                ($config['servers'][$serverId]['version']??'')!==$result['version']) {
                $config['servers'][$serverId]['version']=$result['version'];
                $config['servers'][$serverId]['version_source']='verified Minecraft JAR installation';
                $changed=true;
            }
            if (is_string($result['jar']??null) &&
                ($config['servers'][$serverId]['jar']??'')!==$result['jar']) {
                $config['servers'][$serverId]['jar']=$result['jar'];
                $changed=true;
            }
        }
        if ($operation==='configure_startup' && $state==='succeeded' &&
            isset($config['servers'][$serverId]) &&
            (int)($job['finished']??0)>(int)($config['servers'][$serverId]['startup_applied_at']??0)) {
            foreach(['memory','xms','jvm_args','netty_workdir'] as $key) {
                if (array_key_exists($key,$job['result']??[])) {
                    $config['servers'][$serverId][$key]=$job['result'][$key];
                }
            }
            $config['servers'][$serverId]['startup_applied_at']=(int)($job['finished']??0);
            $changed=true;
        }
        if ($operation==='restore_backup' && $state==='succeeded' &&
            isset($job['result']['version']) && isset($config['servers'][$serverId]) &&
            ($config['servers'][$serverId]['version']??'')!==$job['result']['version']) {
            $config['servers'][$serverId]['version']=$job['result']['version'];
            $changed=true;
        }
        if ($operation==='uninstall' && $state==='succeeded' && !empty($job['result']['removed']) &&
            isset($config['servers'][$serverId])) {
            unset($config['servers'][$serverId]);
            $changed=true;
        }
    }
    if ($changed) save_config($config);
}
if ($authenticated) {
    $all = is_array($config['servers'] ?? null) ? $config['servers'] : [];
    if (isset($_GET['server']) && is_string($_GET['server']) && isset($all[$_GET['server']])) $_SESSION['server_id'] = $_GET['server'];
    $selected = (string)($_SESSION['server_id'] ?? array_key_first($all) ?? '');
    if (!isset($all[$selected])) $selected = (string)(array_key_first($all) ?? '');
    if ($selected !== '') {
        if (($all[$selected]['install_state'] ?? '') === 'pending') $server = null;
        else try { $server = server_from($config, $selected); }
        catch (Throwable $e) { $error = $e->getMessage(); $server = null; }
    }
    if ($server === null && !in_array($tab, ['settings','about','manager'], true)) $tab = 'settings';
}
if (($_GET['action']??'')==='console_poll') {
    if (!$authenticated) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error'=>'Authentication required.']);
        exit;
    }
    try {
        $consoleId=console_server_id(request_string($_GET,'server_id',64));
        if (!isset($config['servers'][$consoleId])) throw new InvalidArgumentException('Unknown Minecraft server.');
        $jobId=request_string($_GET,'job',24);
        console_subscribe($consoleId);
        $payload=['online'=>manager_online(),
            'snapshot'=>console_snapshot($consoleId),
            'status'=>manager_running_state($consoleId)['state']??'unknown',
            'job'=>console_recent_job($consoleId,$jobId)];
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload,JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error'=>'Console status unavailable.']);
    }
    exit;
}
if ($authenticated && $server && ($_GET['action'] ?? '') === 'download_world') {
    try {
        $world = request_string($_GET, 'world', 255);
        $archive = export_world($server, $world);
        try {
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $world) . '.zip"');
            header('Content-Length: ' . filesize($archive));
            header('X-Content-Type-Options: nosniff');
            readfile($archive);
        } finally { @unlink($archive); }
        exit;
    } catch (Throwable $e) { $error = $e->getMessage(); $tab = 'worlds'; }
}
function hidden_csrf(): void { echo '<input type="hidden" name="csrf" value="'.h(csrf_token()).'">'; }
function hidden_server(string $id): void { echo '<input type="hidden" name="server_id" value="'.h($id).'">'; }
function stat_card(string $label, string $value, string $info = ''): void {
    echo '<section class="metric"><small>'.h($label).'</small><strong>'.h($value).'</strong><span>'.h($info).'</span></section>';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= h($tabs[$tab]) ?> · mcmanager</title>
<link rel="icon" href="assets/mcconfig.png">
<link rel="stylesheet" href="assets/style.css?v=0.5.3">
<script src="assets/app.js?v=0.5.3" defer></script>
</head>
<body class="<?= $authenticated ? 'app-mode' : 'auth-mode' ?>">
<?php if (!$authenticated): ?>
<main class="login-shell">
  <div class="login-panel">
    <img src="assets/mcconfig.png" width="76" height="76" alt="mcconfig icon">
    <h1>mcmanager</h1><p class="muted">Minecraft server management</p>
    <?php if ($error): ?><div class="alert danger"><?= h($error) ?></div><?php endif; ?>
    <?php if (!$installed): ?>
      <div class="alert warning">First-time setup. Complete this on a trusted network before exposing your web server publicly.</div>
      <form method="post"><?php hidden_csrf(); ?><input type="hidden" name="action" value="setup">
        <label>Administrator password <input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
        <label>Confirm password <input type="password" name="confirm" required minlength="12" autocomplete="new-password"></label>
        <button type="submit" class="primary">Create administrator</button>
      </form>
    <?php else: ?>
      <form method="post"><?php hidden_csrf(); ?><input type="hidden" name="action" value="login">
        <label>Administrator password <input type="password" name="password" required autocomplete="current-password" autofocus></label>
        <button type="submit" class="primary">Sign in</button>
      </form>
    <?php endif; ?>
    <p class="muted footnote">PHP <?= h(PHP_VERSION) ?> · File-based, no database required</p>
  </div>
</main>
<?php else: ?>
<div class="layout">
<aside class="sidebar">
  <a href="index.php" class="brand"><img src="assets/mcconfig.png" width="42" height="42" alt=""><span>mcmanager<small>Minecraft servers</small></span></a>
  <div class="sidebar-label">WORKSPACE</div>
  <nav aria-label="Main navigation">
  <?php foreach ($tabs as $key=>$title): ?>
    <a href="?tab=<?= h($key) ?>" class="<?= $tab === $key ? 'active' : '' ?>"><?= h($title) ?></a>
  <?php endforeach; ?>
  </nav>
  <div class="sidebar-bottom"><span>PHP <?= h(PHP_VERSION) ?></span><form method="post"><?php hidden_csrf(); ?><button class="link-button" name="action" value="logout">Sign out</button></form></div>
</aside>
<div class="main-area">
<header class="topbar"><button type="button" class="menu-toggle" data-toggle-nav aria-label="Toggle menu">☰</button><div><span class="crumb">mcmanager / </span><strong><?= h($tabs[$tab]) ?></strong></div>
  <?php if ($server): ?><form method="get" class="server-selector"><input type="hidden" name="tab" value="<?= h($tab) ?>"><select name="server" aria-label="Select Minecraft server" data-auto-submit>
    <?php foreach ($all as $id=>$row): ?><option value="<?= h($id) ?>" <?= $id === $selected ? 'selected' : '' ?>><?= h($row['name'] ?? $id) ?></option><?php endforeach; ?>
  </select></form><?php endif; ?>
</header>
<main class="content">
  <div class="heading"><div><h1><?= h($tabs[$tab]) ?></h1><p><?= h($server['name'] ?? 'Configure your Minecraft installation') ?><?= $server ? ' · '.h(ucfirst(server_edition($server))).' · '.h(is_container($server) ? 'Container storage' : 'Native storage') : '' ?></p></div></div>
  <?php if ($message): ?><div class="alert success"><?= h($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert danger"><?= h($error) ?></div><?php endif; ?>
  <?php if ($tab === 'manager'): ?>
    <?php require dirname(__DIR__).'/src/ManagerView.php'; ?>
  <?php elseif ($tab === 'settings'): ?>
    <div class="two-column">
    <section class="panel"><h2>Configured servers</h2><p class="muted">Local filesystem access is required. Nothing is changed in Minecraft until you save a setting.</p>
    <?php if (!$all): ?><p class="empty">No Minecraft installations configured yet.</p><?php endif; ?>
    <?php foreach ($all as $id=>$row): ?>
      <div class="server-row"><div><strong><?= h($row['name'] ?? $id) ?></strong><code><?= h($row['path'] ?? '') ?></code><small><?= h(ucfirst($row['edition'] ?? 'java')) ?> · <?= h(($row['deployment'] ?? 'native') === 'container' ? 'Container bind mount' : 'Native server') ?><?= !empty($row['jar']) ? ' · JAR: '.h($row['jar']) : '' ?></small></div>
      <form method="post" data-confirm="Remove this server from mcconfig? Minecraft files will be left alone."><?php hidden_csrf(); ?><input type="hidden" name="action" value="remove_server"><input type="hidden" name="server_id" value="<?= h($id) ?>"><button class="subtle danger-text">Remove</button></form></div>
    <?php endforeach; ?>
    </section>
    <section class="panel"><h2>Register an existing Minecraft server</h2>
      <p class="hint">This form only registers an existing folder. It does not download Minecraft, create a JAR or generate server.properties. For a new installation, use <a href="?tab=manager">Server Manager → Install a new Minecraft server</a>.</p>
      <form method="post"><?php hidden_csrf(); ?><input type="hidden" name="action" value="add_server">
        <label>Display name <input name="name" placeholder="Minecraft" maxlength="64" required></label>
        <label>Edition <select name="edition"><option value="java">Java (Vanilla, CraftBukkit, Spigot, Paper, Fabric, etc.)</option><option value="bedrock">Bedrock Dedicated Server</option></select></label>
        <label>Deployment <select name="deployment"><option value="native">Native / installed on host</option><option value="container">Container with host bind-mounted data</option></select></label>
        <label>Server implementation <select name="software"><option value="vanilla">Vanilla Java</option><option value="paper">Paper Java</option><option value="spigot">Spigot Java</option><option value="bukkit">CraftBukkit Java</option><option value="fabric">Fabric Java</option><option value="forge">Forge Java</option><option value="neoforge">NeoForge Java</option><option value="bedrock">Bedrock</option></select></label>
        <label>Existing server directory on NAS (must exist) <input name="path" placeholder="/volume1/docker/minecraft/data or /srv/minecraft" required></label>
        <label>Worlds folder (Bedrock only) <input name="worlds_path" placeholder="/volume1/docker/minecraft-bedrock/worlds"></label>
        <label>JAR filename (Java only, optional) <input name="jar" placeholder="server.jar"></label>
        <label>Existing Docker container name (container adoption only) <input name="container_name" placeholder="minecraft-paper"></label>
        <p class="hint">Enter NAS host paths, not /data or other paths inside the container. Java containers usually mount a host folder to /data. Bedrock may use separate config and worlds mounts. The PHP service account needs appropriate ACLs.</p>
        <button class="primary">Register existing server</button>
      </form>
    </section>
    <section class="panel"><h2>Administrator password</h2>
      <form method="post"><?php hidden_csrf(); ?><input type="hidden" name="action" value="password_change">
        <label>Current password <input type="password" name="current" required></label>
        <label>New password <input type="password" name="password" required minlength="12"></label>
        <label>Confirm new password <input type="password" name="confirm" required minlength="12"></label>
        <button class="primary">Change password</button>
      </form>
    </section>
    <section class="panel"><h2>Runtime checks</h2>
      <div class="status-line"><span>PHP version</span><strong><?= h(PHP_VERSION) ?></strong></div>
      <div class="status-line"><span>Private configuration directory</span><strong><?= is_writable(data_dir()) ? 'Writable' : 'Check permissions' ?></strong></div>
      <div class="status-line"><span>PHP session support</span><strong><?= extension_loaded('session') ? 'Enabled' : 'Missing' ?></strong></div>
      <div class="status-line"><span>PHP ZIP support</span><strong><?= extension_loaded('zip') ? 'Enabled' : 'Missing' ?></strong></div>
      <div class="status-line"><span>PHP zlib support</span><strong><?= extension_loaded('zlib') ? 'Enabled' : 'Missing' ?></strong></div>
      <div class="status-line"><span>open_basedir</span><code class="settings-path"><?= h(ini_get('open_basedir') ?: 'Not restricted') ?></code></div>
      <div class="status-line"><span>Upload maximum</span><strong><?= h(ini_get('upload_max_filesize')) ?></strong></div>
      <div class="status-line"><span>Post maximum</span><strong><?= h(ini_get('post_max_size')) ?></strong></div>
      <p class="hint">Generic web hosting can edit accessible Minecraft files without a worker. Installation, lifecycle and container controls require the separate PHP CLI worker.</p>
    </section></div>
  <?php elseif ($tab === 'about'): ?>
    <section class="panel prose"><h2>mcmanager</h2><p>Manage Java and Bedrock installations, settings, worlds, players, versions and backups. Start the optional local worker to provision servers and control native or container workloads.</p>
      <p>The original mcconfig application was released for older Synology DSM systems. mcmanager uses the existing mcconfig package identifier for seamless upgrades. It supports native or host-bind-mounted container Java and Bedrock installations; a DSM 7.2+ package adds a separate PHP CLI management worker.</p>
      <p>Java player lists use UUIDs; Bedrock uses Xbox gamertags/XUIDs. Existing imported servers retain their original installers and ownership. The optional worker performs only approved lifecycle operations and requires NAS permissions. Docker-only console logs remain in Container Manager; not every Minecraft distribution can be installed natively.</p>
      <p><a href="https://github.com/ardenee/mcconfig" target="_blank" rel="noopener noreferrer">GitHub project</a></p></section>
  <?php elseif ($server): ?>
    <?php
      $props = properties_read($server);
      $worlds = list_worlds($server);
      [$logname, $log] = log_text($server);
    ?>
    <?php if ($tab === 'plugins'): ?>
      <?php require dirname(__DIR__).'/src/PluginsView.php'; ?>
    <?php elseif ($tab === 'console'): ?>
      <?php require dirname(__DIR__).'/src/ConsoleView.php'; ?>
    <?php elseif ($tab === 'overview'): ?>
      <div class="metrics"><?php
        stat_card('Configuration', is_file(server_file($server,'server.properties')) ? 'Found' : 'Missing', 'server.properties');
        stat_card('Worlds', (string)count($worlds), 'Detected level.dat folders');
        stat_card('Server log', $logname !== '' ? 'Found' : 'Missing', $logname ?: 'No log configured');
        stat_card('Properties', (string)count($props), 'Editable keys');
      ?></div>
      <div class="two-column">
        <section class="panel"><div class="panel-title"><h2>Server information</h2><a href="?tab=properties">Edit properties</a></div>
          <?php foreach (['motd'=>'Server message', 'server-port'=>'Port', 'max-players'=>'Max players', 'gamemode'=>'Game mode', 'difficulty'=>'Difficulty', 'level-name'=>'Active world'] as $key=>$label): ?>
          <div class="status-line"><span><?= h($label) ?></span><strong><?= h($props[$key] ?? '—') ?></strong></div>
          <?php endforeach; ?>
        </section>
        <section class="panel"><div class="panel-title"><h2>Detected worlds</h2><a href="?tab=worlds">View worlds</a></div>
          <?php if (!$worlds): ?><p class="empty">No worlds with a level.dat file were found.</p><?php endif; ?>
          <?php foreach (array_slice($worlds,0,12) as $world): ?><div class="status-line"><span><?= h($world) ?></span><strong><?= ($props['level-name'] ?? 'world') === $world ? 'Selected' : '' ?></strong></div><?php endforeach; ?>
        </section>
        <section class="panel wide"><div class="panel-title"><h2>Recent log lines</h2><a href="?tab=logs">View logs</a></div><pre class="log-preview"><?= h(implode("\n", array_slice(explode("\n", $log),-18))) ?></pre></section>
      </div>
    <?php elseif ($tab === 'properties'): ?>
      <section class="panel"><div class="panel-title"><div><h2>server.properties</h2><p class="muted">Settings for this specific server; additional keys appear after Minecraft generates them on first startup.</p></div><input id="property-search" placeholder="Filter properties…" aria-label="Filter properties"></div>
        <?php $versionInfo=is_bedrock($server)?['version'=>(string)($server['version']??''),'source'=>'registered Bedrock version']:jar_detect_version($server); ?>
        <div class="status-line"><span>Server</span><strong><?= h($server['name']??$selected) ?></strong></div>
        <div class="status-line"><span>Minecraft version</span><strong><?= h($versionInfo['version']?:'Not yet detected') ?></strong></div>
        <?php if (!is_bedrock($server) && $versionInfo['version']!==''): ?>
          <div class="status-line"><span>Expected Java runtime</span><strong>Java <?= jar_java_required($versionInfo['version']) ?: 'unknown' ?></strong></div>
        <?php endif; ?>
        <p class="hint">Version source: <?= h($versionInfo['source']??'Not available') ?></p>
        <?php if (is_container($server)): ?><div class="alert warning">Container environment variables or startup scripts can override these file settings. The container may generate server.properties only after its first successful start; inspect its /data bind mount.</div><?php endif; ?>
        <?php if (!is_file(server_file($server, 'server.properties'))): ?>
          <div class="alert warning"><strong>No server.properties has been created for this server.</strong>
            The Add Server form registers a folder; it does not download Minecraft or generate its settings. A new Server Manager installation creates this file after the server JAR is downloaded successfully.</div>
          <p class="hint">Registered folder: <code><?= h($server['path']) ?></code></p>
          <?php if (is_container($server)): ?>
            <p>Start the Minecraft container in Server Manager and inspect its startup logs. Ensure the registered directory is the actual container <code>/data</code> bind mount.</p>
          <?php elseif (is_bedrock($server)): ?>
            <p>Bedrock generates its properties when the Bedrock dedicated server first starts. Check the installation and server logs.</p>
          <?php else:
            $name=trim((string)($server['jar']??'')) ?: 'server.jar';
            $file=preg_match('/^[A-Za-z0-9_.-]+\.jar$/iD',$name)?server_file($server,$name):'';
          ?>
            <?php if ($file==='' || !is_file($file)): ?>
              <p>This server does not have its configured JAR (<code><?= h($name) ?></code>). Install a Minecraft server JAR first; mcmanager can then create basic properties.</p>
              <p><a class="subtle" href="?tab=jar&amp;server=<?= h($selected) ?>">Open JAR management</a></p>
            <?php else: ?>
              <p>A server JAR exists, but Minecraft has not generated its settings. You can create a minimal editable configuration without overwriting any existing file.</p>
              <form method="post" data-confirm="Create a new basic server.properties for this stopped server?">
                <?php hidden_csrf();hidden_server($selected); ?>
                <input type="hidden" name="action" value="properties_create">
                <label class="check"><input type="checkbox" name="stopped" required> I confirm this server is stopped.</label>
                <button class="primary">Create basic server.properties</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        <?php else: ?>
        <form method="post"><?php hidden_csrf(); hidden_server($selected); ?><input type="hidden" name="action" value="properties_save">
          <div class="properties-grid">
          <?php foreach ($props as $key=>$value): ?>
            <label class="property" data-property="<?= h(strtolower($key)) ?>"><span><?= h($key) ?></span>
            <?php if (in_array(strtolower($value), ['true','false'], true)): ?>
              <select name="props[<?= h($key) ?>]"><option value="true" <?= strtolower($value) === 'true' ? 'selected' : '' ?>>true</option><option value="false" <?= strtolower($value) === 'false' ? 'selected' : '' ?>>false</option></select>
            <?php else: ?><input name="props[<?= h($key) ?>]" value="<?= h($value) ?>" maxlength="4096" <?= str_contains(strtolower($key), 'password') ? 'autocomplete="off"' : '' ?>><?php endif; ?>
            </label>
          <?php endforeach; ?>
          </div><div class="add-property"><h3>Add property</h3><div class="form-row"><input name="new_key" placeholder="property-name"><input name="new_value" placeholder="Value"></div></div>
          <div class="actions"><button class="primary">Save properties</button><span>Changes are backed up before writing.</span></div>
        </form><?php endif; ?>
      </section>
    <?php elseif ($tab === 'players'): ?>
      <?php if (is_bedrock($server)) { require dirname(__DIR__) . '/src/BedrockPlayersView.php'; } else { ?>
      <div class="two-column">
      <?php foreach (['ops'=>'Operators', 'whitelist'=>'Whitelist', 'banned-players'=>'Banned players', 'banned-ips'=>'Banned IP addresses'] as $type=>$label): ?>
        <section class="panel"><h2><?= h($label) ?></h2>
        <?php try { [$listFile,$mode,$entries] = player_source($server,$type); ?>
          <p class="muted"><?= h(basename($listFile)) ?> · <?= count($entries) ?> entries</p>
          <?php if (!$entries): ?><p class="empty">No entries.</p><?php endif; ?>
          <div class="players">
            <?php foreach ($entries as $i=>$entry): ?>
              <div class="player-row"><div><strong><?= h(is_array($entry) ? ($entry['name'] ?? $entry['ip'] ?? 'Unknown') : $entry) ?></strong>
              <?php if (is_array($entry) && isset($entry['uuid'])): ?><small><?= h($entry['uuid']) ?></small><?php endif; ?></div>
              <form method="post" data-confirm="Remove this entry?"><?php hidden_csrf(); hidden_server($selected); ?>
                <input type="hidden" name="action" value="player_remove"><input type="hidden" name="type" value="<?= h($type) ?>">
                <input type="hidden" name="row" value="<?= $i ?>"><input type="hidden" name="hash" value="<?= h(hash('sha256', json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE))) ?>"><button class="subtle danger-text">Remove</button>
              </form></div>
            <?php endforeach; ?>
          </div>
          <form method="post" class="add-player"><?php hidden_csrf(); hidden_server($selected); ?>
            <input type="hidden" name="action" value="player_add"><input type="hidden" name="type" value="<?= h($type) ?>">
            <label><?= $type === 'banned-ips' ? 'IP address' : 'Username' ?><input name="name" required placeholder="<?= $type === 'banned-ips' ? '192.0.2.1' : 'PlayerName' ?>"></label>
            <?php if ($mode === 'json' && $type !== 'banned-ips'): ?><label>Minecraft UUID <input name="uuid" required placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"></label><?php endif; ?>
            <button class="primary">Add entry</button>
          </form>
        <?php } catch (Throwable $e) { ?><div class="alert danger"><?= h($e->getMessage()) ?></div><?php } ?>
        </section>
      <?php endforeach; ?></div>
      <?php } ?>
    <?php elseif ($tab === 'files'): ?>
      <section class="panel"><h2>Additional configuration files</h2><p class="muted">Edit supported <?= is_bedrock($server) ? 'Bedrock JSON' : 'Bukkit, Spigot and Paper configuration' ?> files. Only known filenames are permitted; backups are saved before changes.</p>
        <div class="file-links"><?php foreach (editable_files($server) as $name=>$label): ?>
          <a class="subtle <?= ($_GET['file'] ?? '') === $name ? 'selected' : '' ?>" href="?tab=files&amp;file=<?= h(rawurlencode($name)) ?>"><?= h($label) ?></a>
        <?php endforeach; ?></div>
        <?php
          $available = array_keys(editable_files($server));
          $fileKey = $_GET['file'] ?? $available[0];
          if (!is_string($fileKey) || !isset(editable_files($server)[$fileKey])) $fileKey = $available[0];
          try { $body = editable_text($server, $fileKey); $fileError = ''; }
          catch (Throwable $e) { $fileError = $e->getMessage(); $body = ''; }
        ?>
        <?php if ($fileError): ?><div class="alert warning"><?= h($fileError) ?></div><?php else: ?>
        <form method="post"><?php hidden_csrf(); hidden_server($selected); ?>
          <input type="hidden" name="action" value="save_text"><input type="hidden" name="file_key" value="<?= h($fileKey) ?>">
          <label><?= h($fileKey) ?><textarea name="content" class="text-editor" spellcheck="false" rows="22"><?= h($body) ?></textarea></label>
          <div class="actions"><button class="primary">Save configuration</button><span>Review <?= is_bedrock($server) ? 'JSON' : 'YAML' ?> syntax before saving; raw files are not validated.</span></div>
        </form><?php endif; ?>
      </section>
    <?php elseif ($tab === 'worlds'): ?>
      <section class="panel"><h2>World directories</h2><p class="muted">Detected using level.dat in the <?= is_bedrock($server) ? 'configured Bedrock worlds directory' : 'server root' ?>. Stop Minecraft before importing or exporting worlds.</p>
        <?php if (!$worlds): ?><p class="empty">No worlds found. Check your configured server directory.</p><?php endif; ?>
        <div class="world-grid"><?php foreach ($worlds as $world): ?>
          <div class="world"><strong><?= h($world) ?></strong><span><?= ($props['level-name'] ?? 'world') === $world ? 'Selected world' : 'Available world' ?></span>
            <a class="subtle" href="?tab=worlds&amp;action=download_world&amp;world=<?= h(rawurlencode($world)) ?>">Download ZIP</a>
            <?php if (($props['level-name'] ?? 'world') !== $world): ?>
            <form method="post" data-confirm="Select this world? The change takes effect after server restart."><?php hidden_csrf(); hidden_server($selected); ?><input type="hidden" name="action" value="select_world"><input type="hidden" name="world" value="<?= h($world) ?>"><button class="subtle">Select world</button></form>
            <?php endif; ?>
          </div><?php endforeach; ?></div>
      </section>
      <section class="panel spaced"><h2>Import a world ZIP</h2>
        <p class="muted">Imports into a new directory only. Existing worlds are never overwritten. ZIP paths and uncompressed sizes are validated before extraction.</p>
        <form method="post" enctype="multipart/form-data"><?php hidden_csrf(); hidden_server($selected); ?><input type="hidden" name="action" value="world_upload">
          <div class="form-row"><label>New world folder name <input name="world_name" required pattern="[A-Za-z0-9_-]{1,64}" placeholder="MyWorld"></label><label>World archive <input type="file" name="world_zip" accept=".zip,.mcworld,application/zip" required></label></div>
          <label class="check"><input type="checkbox" name="stopped" required> The Minecraft server is stopped.</label>
          <button class="primary">Import world</button><p class="hint">ZIP upload maximum: 512 MiB. Maximum uncompressed world: 2 GiB. Requires the PHP zip extension.</p>
        </form>
      </section>
    <?php elseif ($tab === 'logs'): ?>
      <section class="panel"><div class="panel-title"><div><h2><?= h($logname ?: 'Server log') ?></h2><p class="muted">Latest 350 lines, with a 256 KiB read limit.</p></div><a href="?tab=logs" class="subtle">Refresh</a></div><pre class="log-full"><?= h($log) ?></pre></section>
    <?php elseif ($tab === 'jar'): ?>
      <?php require dirname(__DIR__).'/src/ServerJarView.php'; ?>
    <?php endif; ?>
  <?php endif; ?>
</main>
<footer class="footer">mcmanager · Minecraft Server Manager · Actions require administrator authorization</footer>
</div></div>
<?php endif; ?>
</body></html>
