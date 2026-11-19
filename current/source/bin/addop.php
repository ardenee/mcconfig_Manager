<?php
$CONFIG_INI = parse_ini_file("settings.ini", true, INI_SCANNER_RAW);
$parts = explode("|", $CONFIG_INI["Config"]["apps"]);
$game = $CONFIG_INI['Config']['selected'];
$path = $CONFIG_INI[$game]['folder_path'];
//print_r($_GET);

$newer = "";

if(!is_file($CONFIG_INI[$game]['file_ops']))
	$newer = ".converted"; // required for newer versions of Minecraft.

$ops  = file_get_contents($CONFIG_INI[$game]['file_ops'].$newer); 
$ops  = array_unique(explode("\n", $ops));

foreach($_GET as $key=>$val)
{
	if(strlen($val)>0)
		$ops[] = $val;
}

foreach($ops as $key=>$val)
{
	if(strlen($val)==0)
		unset($ops[$key]);
}

sort($ops);
$ops = array_unique($ops);
safefilerewrite($CONFIG_INI[$game]['file_ops'].$newer, implode("\n", $ops));
@rename($CONFIG_INI[$game]['file_ops'].$newer, $CONFIG_INI[$game]['file_ops']);
header("Location: ops.php");

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
