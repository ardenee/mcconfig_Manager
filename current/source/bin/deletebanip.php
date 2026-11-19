<?php
$CONFIG_INI = parse_ini_file("settings.ini", true, INI_SCANNER_RAW);
$parts = explode("|", $CONFIG_INI["Config"]["apps"]);
$game = $CONFIG_INI['Config']['selected'];
$path = $CONFIG_INI[$game]['folder_path'];

$newer = "";

if(!is_file($CONFIG_INI[$game]['file_banips']))
	$newer = ".converted"; // required for newer versions of Minecraft.

$bannedips  = file_get_contents($CONFIG_INI[$game]['file_banips'].$newer); 
$bannedips  = array_unique(explode("\n", $bannedips)); // remove dups
 
foreach($_POST as $key=>$val)
{
	foreach($bannedips as $k=>$v)
	{	
		$strdata = explode($CONFIG_INI[$game]['string_sepseator'], $v);
		
		if(strcmp($val, $strdata[0])==0) 
			unset($bannedips[$k]);	
	}
}

//sort($bannedips); // no need to sort, should already be in order if add performed!
$bannedips = array_unique($bannedips);
safefilerewrite($CONFIG_INI[$game]['file_banips'].$newer, implode("\n", $bannedips));
@rename($CONFIG_INI[$game]['file_banips'].$newer, $CONFIG_INI[$game]['file_banips']);

//json part!!
if(is_file($path."/banned-ips.json"))
{
	$bannedips = file_get_contents($path."/banned-ips.json");
	$bannedips = json_decode($bannedips, true);

	foreach($_POST as $key=>$value)
	{
		foreach($bannedips as $k=>$v)
		{
			if(strcmp($v['ip'], $value)==0)
			{
				unset($bannedips[$k]);
				break;
			}
		}
	}
	
	$bannedips = json_encode($bannedips, JSON_PRETTY_PRINT);
	file_put_contents($path."/banned-ips.json", $bannedips);
}

header("Location: ban.php");

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