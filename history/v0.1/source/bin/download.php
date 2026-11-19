<?php
$file     = $_GET['file'];
$fullfile = "/volume1/web/mcconfig/$file";

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