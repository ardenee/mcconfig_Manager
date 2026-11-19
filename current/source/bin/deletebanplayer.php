<?php
$CONFIG_INI = parse_ini_file("settings.ini", true, INI_SCANNER_RAW);
$parts = explode("|", $CONFIG_INI["Config"]["apps"]);
$game = $CONFIG_INI['Config']['selected'];
$path = $CONFIG_INI[$game]['folder_path'];

$newer = "";

if(!is_file($CONFIG_INI[$game]['file_banplayers']))
	$newer = ".converted"; // required for newer versions of Minecraft.

$bannedplayers  = file_get_contents($CONFIG_INI[$game]['file_banplayers'].$newer); 
$bannedplayers  = array_unique(explode("\n", $bannedplayers)); // remove dups
 
foreach($_POST as $key=>$val)
{
	foreach($bannedplayers as $k=>$v)
	{	
		$strdata = explode($CONFIG_INI[$game]['string_sepseator'], $v);
		
		if(strcmp($val, $strdata[0])==0) 
			unset($bannedplayers[$k]);	
	}
}

//sort($bannedplayers); // no need to sort, should already be in order if add performed!
$bannedplayers = array_unique($bannedplayers);
safefilerewrite($CONFIG_INI[$game]['file_banplayers'].$newer, implode("\n", $bannedplayers));
@rename($CONFIG_INI[$game]['file_banplayers'].$newer, $CONFIG_INI[$game]['file_banplayers']);

//json part!!
if(is_file($path."/banned-players.json"))
{
	$bannedplayers = file_get_contents($path."/banned-players.json");
	$bannedplayers = json_decode($bannedplayers, true);

	foreach($_POST as $key=>$value)
	{
		foreach($bannedplayers as $k=>$v)
		{
			if(strcmp($v['name'], $value)==0)
			{
				unset($bannedplayers[$k]);
				break;
			}
		}
	}
	
	$bannedplayers = json_encode($bannedplayers, JSON_PRETTY_PRINT);
	file_put_contents($path."/banned-players.json", $bannedplayers);
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