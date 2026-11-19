<?php
$world = "";
$path    = '/volume1/@appstore/Minecraft/';

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
{
	//set owner etc..
	
	shell_exec("chowm minecraft:users \"$path.$world\"");
	
	$res = $world;
}
	
//echo "<pre>\n";
//print_r(error_get_last());
//echo "</pre>\n";
header("Location: index.php?res=$res");
?>