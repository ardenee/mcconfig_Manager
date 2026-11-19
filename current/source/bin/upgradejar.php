<?php
$CONFIG_INI = parse_ini_file("settings.ini", true, INI_SCANNER_RAW);
$parts = explode("|", $CONFIG_INI["Config"]["apps"]);
$game  = $CONFIG_INI['Config']['selected'];
$path  = $CONFIG_INI[$game]['folder_path'];

//echo "<pre>";
//print_r($_POST);
//print_r($_FILES);
//echo "</pre>";

if(strlen($_FILES['uploadjarfile']['tmp_name'])>0)
{
	if(is_file($_FILES['uploadjarfile']['tmp_name']))
	{
		$curdate    = new DateTime();
		$curdatestr = $curdate->format('Y-m-d_H-i-s');	
		$path_parts = pathinfo($_POST['curjarfile']);	
		$newname    = $path_parts['dirname']."/".$path_parts['filename']."-".$curdatestr.".".$path_parts['extension'];	
		rename($_POST['curjarfile'], $newname);// backup old jar file	
		move_uploaded_file($_FILES["uploadjarfile"]["tmp_name"], $_POST['curjarfile']);	
	}
}
else if(strlen($_POST['jarfolder'])>0)
{
	header("location: scan.php?folder=".$_POST['jarfolder']."&curjarfile=".$_POST['curjarfile']);
	Exit;
}
else if(strlen($_POST['dljarfile'])>0)
{
	$file       = trim($_POST['dljarfile']);
	$path_parts = pathinfo($file);

	$jarfile = file_get_contents($file);
	file_put_contents($path_parts['basename'], $jarfile);

	if(is_file($path_parts['basename'])) 
	{
		chmod($path_parts['basename'], 0777);
		chown($path_parts['basename'], strtolower($game));
		
		
		$curdate    = new DateTime();
		$curdatestr = $curdate->format('Y-m-d_H-i-s');	
		$path_parts2 = pathinfo($_POST['curjarfile']);	
		$newname    = $path_parts2['dirname']."/".$path_parts2['filename']."-".$curdatestr.".".$path_parts2['extension'];	
		rename($_POST['curjarfile'], $newname);// backup old jar file	
		copy($path_parts['basename'], $_POST['curjarfile']);		
	}
}

header("Location: jarupgrade.php");
?>