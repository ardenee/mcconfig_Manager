<?php
$CONFIG_INI = parse_ini_file("settings.ini", true, INI_SCANNER_RAW);
$parts = explode("|", $CONFIG_INI["Config"]["apps"]);
$game = $CONFIG_INI['Config']['selected'];
$path = $CONFIG_INI[$game]['folder_path'];

date_default_timezone_set('Europe/Spain');//CEST!!
$curdate     = new DateTime();
$testdatastr = "Unknown";

$sp = $CONFIG_INI[$game]['string_sepseator'];

if(strlen($_POST['newuntil'])>0)
{
	$_POST['newuntil'] = str_replace(" ", "-", $_POST['newuntil']);
	$datetime          = strtotime($_POST['newuntil']) + 0;
	$datetime          = date('M d, Y h:i O', $datetime);
	$test              = new DateTime($datetime);
	//$_POST['newuntil'] = $test->format('Y-m-d H:i:s O');
	$testdatastr       = $test->format('Y-m-d H:i:s O');
}

$str = $_POST['newplayer'].$sp.$curdate->format('Y-m-d H:i:s O').$sp.$_POST['newadmin'].$sp.$testdatastr.$sp.$_POST['newfor'];
//echo $str;

$newer = "";

if(!is_file($CONFIG_INI[$game]['file_banips']))
	$newer = ".converted"; // required for newer versions of Minecraft.

$bannedips  = file_get_contents($CONFIG_INI[$game]['file_banips'].$newer); 
$bannedips  = array_unique(explode("\n", $bannedips));
$add            = true;
$comments       = array();

foreach($bannedips as $key=>$val)
{
	if(strlen($val)==0) // remove blanks
	{
		unset($bannedips[$key]); 
		continue;
	}
	
	if(substr(trim($val), 0, 1 ) != "#") // only add valid bans strings
	{
		$strdata = explode($sp, $val);
		
		if(strcmp($val, $strdata[0])==0) // dup found, dont add
			$add=false;		
	}
	else
	{ // copy comments
		$comments[] = $val;
		unset($bannedips[$key]);
	}
}

if($add) // add new ban
	$bannedips[] = $str;	

sort($bannedips); // sort
$bannedips = array_unique($bannedips); // remove dups
$bannedips = array_merge($comments, $bannedips);// comments to the top

safefilerewrite($CONFIG_INI[$game]['file_banips'].$newer, implode("\n", $bannedips));
@rename($CONFIG_INI[$game]['file_banips'].$newer, $CONFIG_INI[$game]['file_banips']);
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
