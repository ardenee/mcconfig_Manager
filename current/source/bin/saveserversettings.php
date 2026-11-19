<?php
$CONFIG_INI = parse_ini_file("settings.ini", true, INI_SCANNER_RAW);
$parts = explode("|", $CONFIG_INI["Config"]["apps"]);
$game  = $CONFIG_INI['Config']['selected'];
$path  = $CONFIG_INI[$game]['folder_path'];

//$path = $_POST['Config']['path'];

//                 su - minecraft -s /bin/sh -c  "/var/packages/Minecraft/scripts/launcher.sh stop minecraft /volume1/@appstore/Minecraft"
//$res = shell_exec("su - minecraft -s /bin/sh -c \"/var/packages/Minecraft/scripts/launcher.sh stop minecraft /volume1/@appstore/Minecraft\"");				  

//echo "<pre>";
//print_r($_POST);

//print_r($res);
//sleep(10);
write_php_ini($_POST, $path."/server.properties");

//su - minecraft -s /bin/sh -c "/var/packages/Minecraft/scripts/launcher.sh start minecraft /volume1/@appstore/Minecraft"
//shell_exec("su - minecraft -s /bin/sh -c \"/var/packages/Minecraft/scripts/launcher.sh start minecraft /volume1/@appstore/Minecraft\" >/dev/null 2>/dev/null &");

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