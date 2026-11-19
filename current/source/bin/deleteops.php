<?php
$CONFIG_INI = parse_ini_file("settings.ini", true, INI_SCANNER_RAW);
$parts = explode("|", $CONFIG_INI["Config"]["apps"]);
$game = $CONFIG_INI['Config']['selected'];
$path = $CONFIG_INI[$game]['folder_path'];

$newer = "";

if(!is_file($CONFIG_INI[$game]['file_ops']))
	$newer = ".converted"; // required for newer versions of Minecraft.

$ops = file_get_contents($CONFIG_INI[$game]['file_ops'].$newer); 
$ops = array_unique(explode("\n", $ops));
 
foreach($_POST as $key=>$val)
{
	foreach($ops as $k=>$v)
		if(strcmp($val, $v)==0 or strlen($v)==0)
			unset($ops[$k]);
}

sort($ops);
$ops = array_unique($ops);
safefilerewrite($CONFIG_INI[$game]['file_ops'].$newer, implode("\n", $ops));
@rename($CONFIG_INI[$game]['file_ops'].$newer, $CONFIG_INI[$game]['file_ops']);

//json part!!
if(is_file($path."/ops.json"))
{
	$ops = file_get_contents($path."/ops.json");
	$ops = json_decode($ops, true);

	foreach($_POST as $key=>$value)
	{
		foreach($ops as $k=>$v)
		{
			if(strcmp($v['name'], $value)==0)
			{
				unset($ops[$k]);
				break;
			}
		}
	}
	
	$ops = json_encode($ops, JSON_PRETTY_PRINT);
	file_put_contents($path."/ops.json", $ops);
}

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