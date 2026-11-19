<?php
$path  = $_POST['Config']['path'];
$parts = explode("|", $CONFIG_INI["Config"]["apps"]);

//                 su - minecraft -s /bin/sh -c  "/var/packages/Minecraft/scripts/launcher.sh stop minecraft /volume1/@appstore/Minecraft"
//$res = shell_exec("su - minecraft -s /bin/sh -c \"/var/packages/Minecraft/scripts/launcher.sh stop minecraft /volume1/@appstore/Minecraft\"");				  

//print_r($res);
//sleep(10);
write_php_ini($_POST, $path."/settings.ini");

//su - minecraft -s /bin/sh -c "/var/packages/Minecraft/scripts/launcher.sh start minecraft /volume1/@appstore/Minecraft"
//shell_exec("su - minecraft -s /bin/sh -c \"/var/packages/Minecraft/scripts/launcher.sh start minecraft /volume1/@appstore/Minecraft\" >/dev/null 2>/dev/null &");

// make sure additional folder are there.
foreach($parts as $key => $val)
{	
	if(!is_dir($_POST[$val]['folder_crashreports']))
	{
		mkdir($_POST[$val]['folder_crashreports'], 0777, true);
		//chmod($_POST[$val]['folder_crashreports'], 0777);
		//chown($_POST[$val]['folder_crashreports'], strtolower($val));
	}

	if(!is_dir($_POST[$val]['folder_plugins']))
	{
		mkdir($_POST[$val]['folder_plugins'], 0777, true);
		//chmod($_POST[$val]['folder_plugins'], 0777);
		//chown($_POST[$val]['folder_plugins'], strtolower($val));
	}
}

header("Location: index.php");
//----------------------------------------------------------------------------------------------
function write_php_ini($array, $file)
{
    $res = array();
	
    foreach($array as $key => $val)
        $res[] = "$key=$val";

    safefilerewrite($file, implode("\n", $res));
}
//----------------------------------------------------------------------------------------------
function safefilerewrite($fileName, $dataToSave)
{    
	if($fp = fopen($fileName, 'w'))
    {
        $startTime = microtime();
		
        do{   
			$canWrite = flock($fp, LOCK_EX);
			
			if(!$canWrite) 
				usleep(round(rand(0, 100)*1000));
        }while((!$canWrite)and((microtime()-$startTime) < 1000));

        if($canWrite)
        {   
			fwrite($fp, $dataToSave);
            flock($fp, LOCK_UN);
        }
		
        fclose($fp);
    }
}
//----------------------------------------------------------------------------------------------
?>