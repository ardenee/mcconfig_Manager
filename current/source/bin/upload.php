<?php
$CONFIG_INI = parse_ini_file("settings.ini", true, INI_SCANNER_RAW);
$parts = explode("|", $CONFIG_INI["Config"]["apps"]);
$game = $CONFIG_INI['Config']['selected'];
$path = $CONFIG_INI[$game]['folder_path'];

if(isset($_GET['name']))
	$comp = $_GET['name'];
	
//echo "<pre>";
//print_r($_GET);
//print_r($_FILES);
//echo "</pre>";
	
if($_FILES["uploadjarfile"]["error"] == UPLOAD_ERR_OK) 
{
	$curdate    = new DateTime();
	$curdatestr = $curdate->format('Y-m-d_H-i-s');	
	$path_parts = pathinfo($path."/".$game.".jar");	
	$newname    = $path_parts['dirname']."/".$path_parts['filename']."-".$curdatestr.".".$path_parts['extension'];	
	rename($_GET['curjarfile'], $newname);// backup old jar file	
	move_uploaded_file($_FILES["uploadjarfile"]["tmp_name"], $_POST['curjarfile']);		
}
/*
if(is_file($comp)) 
{
	$curdate    = new DateTime();
	$curdatestr = $curdate->format('Y-m-d_H-i-s');	
	$path_parts = pathinfo($path."/".$game.".jar");		
	$newname    = $path_parts['dirname']."/".$path_parts['filename']."-".$curdatestr.".".$path_parts['extension'];	
	rename($_POST['curjarfile'], $newname);// backup old jar file	
	move_uploaded_file($comp, $_POST['curjarfile']);		
}
*/


header("location: jarupgrade.php");	
?>