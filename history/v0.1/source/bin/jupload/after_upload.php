<?php
/**
 * This page is displayed by the applet, once all files are uploaded. You can add here any post-treatment you need.
 * 
 * This page content: just display the list of uploaded files. 
 */


session_start();
$paths = array();

foreach ($_SESSION['juvar.files'] as $f) {
	$paths[] = $f['path'];
	//chown($f['path'], "minecraft");
	//chgrp($f['path'], "users");
}

$paths = array_unique($paths);

foreach ($paths as $p) {
	//chown($p, "minecraft");
	//chgrp($p, "minecraft");
	//shell_exec("chown -R minecraft:users \"$p\"");
	//echo "chown -R minecraft:users \"$p\"<BR>";
}

header("Location: ../index.php");
?>