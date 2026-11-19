<?php
$CONFIG_INI = parse_ini_file("settings.ini", true, INI_SCANNER_RAW);
$parts = explode("|", $CONFIG_INI["Config"]["apps"]);
$game = $CONFIG_INI['Config']['selected'];
$path = $CONFIG_INI[$game]['folder_path'];

$world = "";

if(isset($argv[1])) // running a scan! locally, can delete in build
	$world = $argv[1];
else if(isset($_GET['world']))
	$world = $_GET['world'];
else
{
	header("Location: index.php");
	Exit;
}

if(mkdir($path.$world, 0775, true)) // required for moving and no folder flattening
{	//set owner etc..	
	shell_exec("chowm ".$CONFIG_INI[$game]['string_name'].":users \"$path.$world\"");
	shell_exec("chmod -R 775 \"$path.$world\"");
	
	$res = $world;
}

header("Location: index.php?res=$res");
?>