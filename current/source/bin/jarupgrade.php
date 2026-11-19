<?php
$CONFIG_INI = parse_ini_file("settings.ini", true, INI_SCANNER_RAW);
$parts      = explode("|", $CONFIG_INI["Config"]["apps"]);
$game       = $CONFIG_INI['Config']['selected'];
$path  = $CONFIG_INI[$game]['folder_path'];
?>

<!DOCTYPE HTML>
<HTML>
 <HEAD>
  <TITLE>mcconfig</TITLE>
  <META CHARSET="utf-8" />
  <META CONTENT="IE=edge,chrome=1" HTTP-EQUIV="X-UA-Compatible" />
  <META NAME="robots" CONTENT="noindex" />
  <META NAME="GENERATOR" CONTENT="MSHTML 8.00.7601.17940" />
  <!--[if lt IE 9]>
    <SCRIPT SRC="files/html5.js"></SCRIPT>
  <![endif]--> 
  <LINK REL="shortcut icon" HREF="images/mcconfig.png" />
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/bootstrap.css" /> 
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/jquery.pnotify.default.css" />  
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/jquery-ui-1.8.24.custom.css" />
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/jquery.qtip.css" />
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/style.css" /> 
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/imgs.css" /> 
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/tcal.css" />
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/tabs.css" />
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/tabs-panes.css" />   
  <SCRIPT TYPE="text/javascript" SRC="files/tcal.js"></SCRIPT>   
  <SCRIPT TYPE="text/javascript" SRC="files/jquery-1.7.2.min.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/jquery-ui-1.8.24.custom.min.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/bootstrap.min.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/jquery.cookie.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/jquery.cookiejar.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/jquery.json-2.2.min.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/jquery.selectboxes.min.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/jquery.tablesorter-2.1.19.min.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/jquery.tablesorter.widgets.min.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/jquery.qtip-2012-04-26.min.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/jquery.pnotify-1.2.0.min.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/jquery.expand-1.3.8.js"></SCRIPT>  
  <SCRIPT TYPE="text/javascript" SRC="files/jquery.form-2.92.js"></SCRIPT>  
  <SCRIPT TYPE="text/javascript" CHARSET="utf-8">
    sbRoot = '/'; 
    top_image_html = '<IMG SRC="images/top.gif" STYLE="width:31px; height:11px" alt="Jump to top" />'; 
  </SCRIPT>  
  <SCRIPT TYPE="text/javascript" SRC="files/jquery.scrolltopcontrol-1.1.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/ajaxNotifications.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/script2.js"></SCRIPT>    
  <SCRIPT TYPE="text/javascript" SRC="files/gen_validatorv4.js"></SCRIPT>     
  <SCRIPT TYPE="text/javascript" SRC="files/jquery.tools.min.js"></SCRIPT> 
  <SCRIPT TYPE="text/javascript" SRC="files/jquery.textchange.min.js"></SCRIPT>  
  <SCRIPT type="text/javascript">
    $.Browser = {
      defaults: {
      title:             'Choose Directory',
      url:               'browser-files.php',
      autocompleteURL:   'browser-files.php'
      }
    };
  </SCRIPT>
  <STYLE TYPE="text/css">
    table.one {border-collapse:collapse;}
    td.a {
      border-style:solid; 
      border-width:1px; 
      border-color:#000000; 
      padding: 2px;
    }
  </STYLE>		
 </HEAD>
 
<HEADER>
 <DIV id=SubMenu><SPAN>
 <A HREF="index.php">Home</A> | 
 <A HREF="log.php">Logs</A> | 
 <A HREF="ops.php">Ops</A> | 
 <A HREF="ban.php">Ban</A> | 
 <A HREF="whitelist.php">Whitelist</A> | 
 <A HREF="jarupgrade.php">jar Upgrade</A> | 
 <A HREF="settings.php">Settings</A> | 
 <A target="_Blank" HREF="http://minecraft.gamepedia.com/Server.properties">Help</A> | 
 <A HREF="about.php">About</A> | 
 
    <select name="game" id="game" class="input-medium" OnChange="UpdateGame(this.options[this.selectedIndex].value);">
<?php
		foreach($parts as $key => $value)
			echo "        <option value=\"$value\"".(($game==$value)?" SELECTED":"").">$value</option>\n";
?>
    </select> |
	<A HREF="feedback.php">Feedback</A> 
 </SPAN></DIV>
</HEADER>

 <BODY> 
	<div id="contentWrapper">
	<div id="content">
<?php
$running = IsMCrunning($game);

if($running)
{
	echo "<span align=center><font color=red><b><center>$game is still running, you cannot upgrade the jar file until you stop $game.</center></b></font></span>";
	echo "<BR>You can start and stop $game via the following<BR><BR>";
	echo "The Synology Package Center (Start and Stop options)<BR>";	
	echo "ssh command to Stop: <u><i>su - ".strtolower($game)." -s /bin/sh -c  \"/var/packages/$game/scripts/launcher.sh stop ".strtolower($game)." $path\"</i></u><BR>";
	echo "ssh command to Start: <u><i>su - ".strtolower($game)." -s /bin/sh -c \"/var/packages/$game/scripts/launcher.sh start ".strtolower($game)." $path\"</i></u><HR>";
}

function IsMCrunning($Game)
{
	$d = shell_exec("ps | grep ".strtolower($Game)."");
	$d = explode("\n", $d);
	
	foreach($d as $key => $val)
	{
		$val = trim($val);
		$val = trim(substr($val, strpos($val, " "), strlen($val)));
		$val = trim(substr($val, 0, strpos($val, " ")));
		
		if(strstr($val, substr(strtolower($Game), 0, 7)))
			return true;
	}
	
	return false;
}
?>

<h1 class="title">jar file upgrade - '<?php echo $game; ?>'</h1>

<div id="config">
 <div id="config-content">
  <form name="jarupgrade" id="jarupgrade" action="upgradejar.php" method="post" enctype="multipart/form-data">
   <div id="config-components">
    <div id="core-component-group1" class="component-group clearfix">
    <div class="component-group-desc">
 	 <h3>Upgrade</h3>
 	 <p>Source Options</p>
 	</div> <!--component-group-desc-->
	
<fieldset class="component-group-list">
 <div class="field-pair">
  <label class="nocheck clearfix">
   <span class="component-title">jar File to Upload</span>
   <input type="file" name="uploadjarfile" id="uploadjarfile" value="">
  </label>
     <label class="nocheck clearfix">
	<span class="component-title">&nbsp;</span>
	<span class="component-desc" id="op-permission-level-comment">Select from your computer.</span>
   </label>
 </div>
<hr>

 
 <div class="field-pair">
  <label class="nocheck clearfix" for="jarfolder">
   <span class="component-title">jar Download Folder</span>
   <input type="text" name="jarfolder" id="jarfolder" value="" size="35" readonly>		   
  </label>
    <label class="nocheck clearfix">
	<span class="component-title">&nbsp;</span>
	<span class="component-desc" id="op-permission-level-comment">Select from your Synology system.</span>
   </label>
 </div> 
 
<hr>
 <div class="field-pair"> 
  <label class="nocheck clearfix">
   <span class="component-title">Download Link <a href="<?php echo $CONFIG_INI[$game]['url_info']; ?>" target="_Blank">Source</a></span>
   <span class="component-desc" id="op-permission-level-comment"><a href="<?php echo $CONFIG_INI[$game]['url_upgrade']; ?>" target="_Blank">All Server Links</a></span>
  </label>
<BR>
<?php
$links = file_get_contents($CONFIG_INI[$game]['url_upgrade']); // servers
$links = explode("\n", $links);
echo "<pre>\n";
echo " <label class=\"nocheck clearfix\"><span class=\"component-desc\">";	
foreach($links as $key => $val)
{
	$path_parts = pathinfo(trim($val));
	$file       = $path_parts['basename'];
	
	if(substr(trim($file), 0, strlen($game)) == strtolower($game) or $file == "craftbukkit.jar") // only add valid bans strings
	{
		$folder = substr($path_parts['dirname'], (strlen($path_parts['dirname'])-40), 43);
		echo "<span class=\"component-desc\"><input type=\"radio\" id=\"dljarfile\" name=\"dljarfile\" value=\"".trim($val)."\" title=\"$val\"><a href=\"$val\">$folder/<b>".$path_parts['basename']."</b></a></span>";
	}
}

echo "   </pre>\n";
echo "  </span>\n</label>\n";
echo "<br>\n";
?>

   <label class="nocheck clearfix">
	<span class="component-title">&nbsp;</span>
	<span class="component-desc" id="op-permission-level-comment">Select from on line resource above.</span>
   </label>   
   <label class="nocheck clearfix">
	<span class="component-desc" id="op-permission-level-comment"><BR><BR>Note, the upgrade is performed in the above order, depending on which options you set. eg, if you select a file from your computer, and select a file to download, the file from your computer will be used instead. Use the <B>Reset</b> button at the bottom of the page to clear any unwanted selections.</span>
   </label>
 </div>
 <hr>
</fieldset>

    <div class="component-group-desc">
 	 <h3>Current</h3>
 	 <p>Options</p>
 	</div> <!--component-group-desc-->
	
<fieldset class="component-group-list">
 <div class="field-pair">
  <label class="nocheck clearfix">
   <span class="component-title">jar File to replace</span>
   <input type="text" id="curjarfile" name="curjarfile" value="<?php echo $CONFIG_INI[$game]['file_jar']; ?>" size="50" readonly/>
  </label>
  <label class="nocheck clearfix">
   <span class="component-title">&nbsp;</span>
 <?php  
 $size    = filesize($CONFIG_INI[$game]['file_jar']);
 $date    = filemtime($CONFIG_INI[$game]['file_jar']); 
 $datestr = date ("d-m-Y H:i:s", $date);
 $sizestr = number_format($size)." bytes";
 echo "   <span class=\"component-desc\" id=\"op-permission-level-comment\">Date: $datestr<BR>Size: $sizestr</span>";
 ?>
  </label>
 </div>
 <div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title">Backup old jar file.</span>
  <input type="checkbox" id="backupjarfile" name="backupjarfile" CHECKED disabled/>
 </label> 
</div>

 <div class="component-group-save">
  <input type="reset" class="btn config_reset" value="Reset" />
  
  <?php  
	echo "<input type=\"submit\" class=\"btn config_submitter\" value=\"Upgrade\"".(($running)?"DISABLED":"")."/>";
?>
 </div>
</fieldset>


</div><!-- /component-group1 //-->
 </div><!-- /config-components -->
 </form>
 </div>
</div>
   </DIV><!-- /content -->
  </DIV><!-- /contentWrapper -->
   <FOOTER>
   <DIV class="container footer"><B>mcconfig jar upgrade</b></DIV><!-- /container footer -->
   </FOOTER>
 </BODY>
</HTML> 
<script type="text/javascript" charset="utf-8">
<!--
$(document).ready(function(){
	$('#jarfolder').fileBrowser({ title: 'Select jar Download Folder' });
});
//-->
</script>

<SCRIPT type="text/javascript">
function UpdateGame(selected)
{
	window.location = "updateselectedgame.php?game="+selected;	
}
</SCRIPT>
