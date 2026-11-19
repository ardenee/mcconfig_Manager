<?php
$CONFIG_INI = parse_ini_file("settings.ini", true, INI_SCANNER_RAW);
$parts = explode("|", $CONFIG_INI["Config"]["apps"]);
$game = $CONFIG_INI['Config']['selected'];
$path = $CONFIG_INI[$game]['folder_path'];

if(isset($_POST['name']))
	$file = $_POST['name'];	
else if(isset($_GET['name']))
	$file = $_GET['name'];
		
$path_parts = pathinfo($file);
$jarfile = file_get_contents($file);// change to curl!!
@unlink($path_parts['basename']);// elete if file already downloaded
file_put_contents($path_parts['basename'], $jarfile);
//header("location: upgrade.php?file=/volume1/web/mmconfig/tmp-minecraft.jar");	

if(is_file($path_parts['basename'])) 
{
	$curdate     = new DateTime();
	$curdatestr  = $curdate->format('Y-m-d_H-i-s');	
	$path_parts2 = pathinfo($path."/".$CONFIG_INI[$game]['name'].".jar");	
	$newname     = $path_parts2['dirname']."/".$path_parts2['filename']."-".$curdatestr.".".$path_parts2['extension'];	
	rename($_POST['curjarfile'], $newname);// backup old jar file	
	copy($path_parts['basename'], $path."/".$CONFIG_INI[$game]['name'].".jar");		
}

header("location: jarupgrade.php");	
?>