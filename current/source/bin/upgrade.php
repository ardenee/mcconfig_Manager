<?php
if(isset($_POST['dljarfile']))
	$file = $_POST['dljarfile'];
	
if(is_file($file) and $file!=$_POST['curjarfile']) 
{
	$curdate    = new DateTime();
	$curdatestr = $curdate->format('Y-m-d_H-i-s');	
	$path_parts = pathinfo($_POST['curjarfile']);	
	$newname    = $path_parts['dirname']."/".$path_parts['filename']."-".$curdatestr.".".$path_parts['extension'];	
	rename($_POST['curjarfile'], $newname);// backup old jar file	
	copy($file, $_POST['curjarfile']);		
}

header("location: jarupgrade.php");	
?>