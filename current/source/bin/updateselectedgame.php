<?php
$CONFIG_INI = parse_ini_file("settings.ini", true, INI_SCANNER_RAW);
$game = "";

if(isset($_GET['game']))
{}
else
{
	header("Location.index.php");
	Exit;
}

$CONFIG_INI = parse_ini_file("settings.ini", true, INI_SCANNER_RAW);
$CONFIG_INI['Config']['selected']=$_GET['game'];
write_php_ini($CONFIG_INI, $CONFIG_INI['Config']['path']."/settings.ini");
header("Location: index.php");

//----------------------------------------------------------------------------------------------
function write_php_ini($array, $file)
{
    $res = array();
    foreach($array as $key => $val)
    {
        if(is_array($val))
        {
            $res[] = "[$key]";
            foreach($val as $skey => $sval) {
                if (is_array($sval)) {
                    foreach ($sval as $i=>$v) {
                        $res[] = "{$skey}[$i] = $v";
                    }
                }
                else {
                    $res[] = "$skey = $sval";
                }
            }
        }
        else $res[] = "$key = $val";
    }
    safefilerewrite($file, implode("\r\n", $res));
}

//////
function safefilerewrite($fileName, $dataToSave)
{    
    file_put_contents($fileName, $dataToSave, LOCK_EX);    
}


?>