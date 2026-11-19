<?php
$CONFIG_INI = parse_ini_file("settings.ini", true, INI_SCANNER_RAW);

$file     = $_GET['file'];
$fullfile = $CONFIG_INI['Config']['tmp']."/".$file;

if($fd = fopen($fullfile, "r")) 
{		
	$fsize = filesize($fullfile);
	header("Content-type: application/octet-stream");
	header("Content-Disposition: filename=$file");	
	header("Content-length: $fsize");
	header("Cache-control: private"); //use this to open files directly
	
	while(!feof($fd)) 
	{
		$buffer = fread($fd, 2048);
		echo $buffer;
	}
}

fclose ($fd);
@unlink($fullfile);
?>