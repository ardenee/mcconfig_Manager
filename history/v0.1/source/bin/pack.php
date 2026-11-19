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
	$folder = $_GET['path'];
	$file = "\"/volume1/web/mcconfig/$folder.tar\"";
	@unlink($file);

	$path   = "/volume1/@appstore/Minecraft/$folder";		
	$res    = shell_exec("tar -cf \"$file\" -C \"$path\" \"../$folder\"");	//cvzf
	$size   = @filesize($file);

	do
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
