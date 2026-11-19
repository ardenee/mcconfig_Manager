<?php
$CONFIG_INI = parse_ini_file("settings.ini", true, INI_SCANNER_RAW);
$parts = explode("|", $CONFIG_INI["Config"]["apps"]);
$game = $CONFIG_INI['Config']['selected'];
$path = $CONFIG_INI[$game]['folder_path'];

$newer = "";

if(!is_file($CONFIG_INI[$game]['file_whitelist']))
	$newer = ".converted"; // required for newer versions of Minecraft.

$whitelist  = file_get_contents($CONFIG_INI[$game]['file_whitelist'].$newer); 
$whitelist  = array_unique(explode("\n", $whitelist));

foreach($_GET as $key=>$val)
{
	if(strlen($val)>0)
		$whitelist[] = $val;
}

foreach($whitelist as $key=>$val)
{
	if(strlen($val)==0)
		unset($whitelist[$key]);
}

sort($whitelist);
$whitelist = array_unique($whitelist);
safefilerewrite($CONFIG_INI[$game]['file_whitelist'].$newer, implode("\n", $whitelist));
@rename($CONFIG_INI[$game]['file_whitelist'].$newer, $CONFIG_INI[$game]['file_whitelist']);
header("Location: whitelist.php");

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
