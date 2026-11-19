<?php
session_start();
$paths = array();

$CONFIG_INI = parse_ini_file("../settings.ini", true, INI_SCANNER_RAW);
$parts = explode("|", $CONFIG_INI["Config"]["apps"]);
$game  = $CONFIG_INI['Config']['selected'];
$path  = $CONFIG_INI[$game]['folder_path'];

foreach ($_SESSION['juvar.files'] as $f) {
	$paths[] = $f['path'];
	//chown($f['path'], strtolower($game));
	//chgrp($f['path'], "users");
	//chmod($f['path'],0777);
}

$paths = array_unique($paths);

foreach ($paths as $p) {
	//chown($p, strtolower($game));
	//chgrp($p, "users");
	//shell_exec("chown -R minecraft:users \"$p\"");
	//echo "chown -R minecraft:users \"$p\"<BR>";
	//chmod($f['path'],0777);
}

header("Location: ../index.php");
?>