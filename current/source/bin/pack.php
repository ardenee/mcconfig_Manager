<?php
$CONFIG_INI = parse_ini_file("settings.ini", true, INI_SCANNER_RAW);
$parts      = explode("|", $CONFIG_INI["Config"]["apps"]);
$game       = $CONFIG_INI['Config']['selected'];
$path       = $CONFIG_INI[$game]['folder_path'];
?>

<script type="text/javascript">  
function Download(file) 
{
    var newwindow=window.open("download.php?file="+file,"Download File",'height=50,width=100,menubar=no,resizable=no,scrollbars=no,titlebar=no,toolbar=no');   
	//setTimeout(function () { newwindow.close();}, 3000);
	window.location = "index.php";
}
</SCRIPT> 	

<?php
if(isset($_GET['path']))
{
	$folder = $_GET['path']; // folder(map) to download!
	//$file   = "\"/volume1/web/mcconfig/$folder.tar\"";
	$file   = "\"".$CONFIG_INI['Config']['tmp']."/$folder.tar\"";
	
	@unlink($file);

	$dlpath = $path."/".$folder;		
	$res    = shell_exec("tar -cf \"$file\" -C \"$dlpath\" \"../$folder\"");	//cvzf
	$size   = @filesize($file);

	do // keep checking untill the file is archived and ready to download!
	{
		$size = @filesize($file);
		sleep(2);
	}
	while($size!=@filesize($file));
	
	echo "<body onload=\"Download('$folder.tar');\">";
}
else
	header("Location: index.php");
?>
