<?php
$worlds     = array();
$world      = "";
$path       = '/volume1/@appstore/Minecraft';
$CONFIG_INI = parse_ini_file($path."/server.properties", FALSE, INI_SCANNER_RAW);
//echo "<pre>";
//print_r($CONFIG_INI);
//echo "</pre>";

if(!isset($CONFIG_INI["op-permission-level"]))
	$CONFIG_INI["op-permission-level"] = 1;
	
if(!isset($CONFIG_INI["player-idle-timeout"]))
	$CONFIG_INI["player-idle-timeout"] = 0;	

$results = scandir($path);

foreach ($results as $result) {
    if ($result === '.' or $result === '..') continue;

    if (is_dir($path . '/' . $result)) 
		$worlds[$result]=$result;
}

unset($worlds["logs"]);
unset($worlds["crash-reports"]);

if(isset($_GET["world"]))// level-name
{
	$world = $_GET["world"];
	$CONFIG_INI["level-name"] = $world;
}
?>
<!DOCTYPE HTML>
<HTML>
 <HEAD>
  <TITLE>Minecraft Config</TITLE>
  <META CHARSET="utf-8">
  <META content="IE=edge,chrome=1" http-equiv="X-UA-Compatible">
  <META name="robots" content="noindex">
  <!--[if lt IE 9]>
  <SCRIPT SRC="files/html5.js"></SCRIPT>
  <![endif]--> 
  <LINK REL="shortcut icon" HREF="images/mcconfig.png">
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/bootstrap.css"> 
  <link rel="stylesheet" type="text/css" href="files/jquery.pnotify.default.css">  
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/jquery-ui-1.8.24.custom.css">
  <link rel="stylesheet" type="text/css" href="files/jquery.qtip.css"/>
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/style.css"> 
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/imgs.css"> 
  
  <SCRIPT TYPE="text/javascript" SRC="files/jquery-1.7.2.min.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/jquery-ui-1.8.24.custom.min.js"></SCRIPT>
  <script type="text/javascript" src="files/bootstrap.min.js"></script>
  <script type="text/javascript" src="files/jquery.cookie.js"></script>
  <script type="text/javascript" src="files/jquery.cookiejar.js"></script>
  <script type="text/javascript" src="files/jquery.json-2.2.min.js"></script>
  <script type="text/javascript" src="files/jquery.selectboxes.min.js"></script>
  <sCRIPT TYPE="text/javascript" SRC="files/jquery.tablesorter-2.1.19.min.js"></SCRIPT>
  <script type="text/javascript" src="files/jquery.tablesorter.widgets.min.js"></script>
  <script type="text/javascript" src="files/jquery.qtip-2012-04-26.min.js"></script>
  <script type="text/javascript" src="files/jquery.pnotify-1.2.0.min.js"></script>
  <script type="text/javascript" src="files/jquery.expand-1.3.8.js"></script>  
  <SCRIPT TYPE="text/javascript" SRC="files/jquery.form-2.92.js"></SCRIPT>  
  <SCRIPT TYPE="text/javascript" CHARSET="utf-8">
	    sbRoot = '/'; 
        top_image_html = '<img src="images/top.gif" style="width:31px; height:11px" alt="Jump to top" />'; 
  </SCRIPT>  
   <SCRIPT TYPE="text/javascript" SRC="files/jquery.scrolltopcontrol-1.1.js"></SCRIPT>
   <script type="text/javascript" src="files/ajaxNotifications.js"></script>
   <SCRIPT TYPE="text/javascript" SRC="files/script.js"></SCRIPT>    
   <script type="text/javascript" src="files/gen_validatorv4.js"></script>
   
   <SCRIPT type="text/javascript">
    <!--
        $(document).ready(function(){ 
            $("#NAVconfig").addClass("active");
        });
    //-->
    </SCRIPT>

  <META name="GENERATOR" content="MSHTML 8.00.7601.17940">
 </HEAD>

 <BODY onload="updateop(<?php echo $CONFIG_INI["op-permission-level"]; ?>);">
  <DIV id=SubMenu><SPAN><A target="_Blank" HREF="http://minecraft.gamepedia.com/Server.properties">Help</A> | <A HREF="about.php">About</A> | <A HREF="log.php">Minecraft Log</A></SPAN></DIV>
 </HEADER>

	<div id="contentWrapper">
	<div id="content">
<?php
if(IsMCrunning())
{
	echo "<span align=center><font color=red><b><center>Minecraft is still running, saving won't take effect until it's been restarted!</center></b></font></span>";
	echo "<BR>You can start and stop Minecraft via the following<BR><BR>";
	echo "The Synology Package Center (Start and Stop options)<BR>";
	echo "ssh command to Stop: <i>su - minecraft -s /bin/sh -c  \"/var/packages/Minecraft/scripts/launcher.sh stop minecraft /volume1/@appstore/Minecraft\"</i><BR>";
	echo "ssh command to Start: <i>su - minecraft -s /bin/sh -c \"/var/packages/Minecraft/scripts/launcher.sh start minecraft /volume1/@appstore/Minecraft\"</i><HR>";
}	

function IsMCrunning()
{
	$d = shell_exec("ps | grep minecraft");
	$d = explode("\n", $d);
	
	foreach($d as $key => $val)
	{
		$val = trim($val);
		$val = trim(substr($val, strpos($val, " "), strlen($val)));
		$val = trim(substr($val, 0, strpos($val, " ")));
		
		if(strstr($val, "minecraf"))
			return true;
	}
	
	return false;
}


?>



<h1 class="title">server.properties</h1>



<div id="config">
 <div id="config-content">
  <form name="configForm" id="configForm" action="saveinisettings.php" method="post">
   <div id="config-components">
    <div id="core-component-group1" class="component-group clearfix">
    <div class="component-group-desc">
 	 <h3>Minecraft</h3>
 	 <p>Options</p>
 	</div> <!--component-group-desc-->

	
	
	
	
<fieldset class="component-group-list">
 <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title">allow-flight</span>
   <span class="component-desc">
    <select name="allow-flight" id="allow-flight" class="input-medium" >
     <option value="true"<?php echo ((strtolower($CONFIG_INI['allow-flight'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($CONFIG_INI['allow-flight'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div>
 
  <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title">allow-nether</span>
   <span class="component-desc">
    <select name="allow-nether" id="allow-nether" class="input-medium" >
     <option value="true"<?php echo ((strtolower($CONFIG_INI['allow-nether'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($CONFIG_INI['allow-nether'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div>
 
  <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title">allow-nether</span>
   <span class="component-desc">
    <select name="announce-player-achievements" id="announce-player-achievements" class="input-medium" >
     <option value="true"<?php echo ((strtolower($CONFIG_INI['announce-player-achievements'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($CONFIG_INI['announce-player-achievements'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 

 
   <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title">difficulty</span>
   <span class="component-desc">
    <select name="difficulty" id="difficulty" class="input-medium" >
     <option value="0"<?php echo ((strtolower($CONFIG_INI['difficulty'])=="0")?" SELECTED":""); ?>>Peaceful</option>
     <option value="1"<?php echo ((strtolower($CONFIG_INI['difficulty'])=="1")?" SELECTED":""); ?>>Easy</option>
	 <option value="2"<?php echo ((strtolower($CONFIG_INI['difficulty'])=="2")?" SELECTED":""); ?>>Normal</option>
	 <option value="3"<?php echo ((strtolower($CONFIG_INI['difficulty'])=="3")?" SELECTED":""); ?>>Hard</option>
    </select>
   </span>
   </label>
 </div> 
 
 
  <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title">enable-query</span>
   <span class="component-desc">
    <select name="enable-query" id="enable-query" class="input-medium" >
     <option value="true"<?php echo ((strtolower($CONFIG_INI['enable-query'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($CONFIG_INI['enable-query'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 
   <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title">enable-rcon</span>
   <span class="component-desc">
    <select name="enable-rcon" id="enable-rcon" class="input-medium" >
     <option value="true"<?php echo ((strtolower($CONFIG_INI['enable-rcon'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($CONFIG_INI['enable-rcon'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 
   <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title">enable-command-block</span>
   <span class="component-desc">
    <select name="enable-command-block" id="enable-command-block" class="input-medium" >
     <option value="true"<?php echo ((strtolower($CONFIG_INI['enable-command-block'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($CONFIG_INI['enable-command-block'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 
   <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title">force-gamemode</span>
   <span class="component-desc">
    <select name="force-gamemode" id="force-gamemode" class="input-medium" >
     <option value="true"<?php echo ((strtolower($CONFIG_INI['force-gamemode'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($CONFIG_INI['force-gamemode'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 
 

 <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title">gamemode</span>
   <span class="component-desc">
    <select name="gamemode" id="gamemode" class="input-medium" >
     <option value="0"<?php echo ((strtolower($CONFIG_INI['gamemode'])=="0" )?" SELECTED":""); ?>>Survival</option>
     <option value="1"<?php echo ((strtolower($CONFIG_INI['gamemode'])=="1")?" SELECTED":""); ?>>Creative</option>
	 <option value="2"<?php echo ((strtolower($CONFIG_INI['gamemode'])=="2")?" SELECTED":""); ?>>Adventure</option>
    </select>
   </span>
   </label>
 </div> 
 
 
   <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title">generate-structures</span>
   <span class="component-desc">
    <select name="generate-structures" id="generate-structures" class="input-medium" >
     <option value="true"<?php echo ((strtolower($CONFIG_INI['generate-structures'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($CONFIG_INI['generate-structures'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 

<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title">generator-settings</span>
  <input type="text" id="generator-settings" name="generator-settings" value="<?php echo $CONFIG_INI['generator-settings']; ?>" size="50" />
 </label>
</div>


 <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title">hardcore</span>
   <span class="component-desc">
    <select name="hardcore" id="hardcore" class="input-medium" >
     <option value="true"<?php echo ((strtolower($CONFIG_INI['hardcore'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($CONFIG_INI['hardcore'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 

 <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title">level-name</span>
   <span class="component-desc">
    <select name="level-name" id="level-name" class="input-medium" >
	<?php
		foreach($worlds as $key => $value)
			echo "<option value=\"$value\"".((($CONFIG_INI['level-name'])=="$value")?" SELECTED":"").">$value</option>\n";
	?>
    </select> <a href="javascript:DownloadWorld();">Download Selected Level</a>
   </span>
   </label>
   <label class="nocheck clearfix">
	<span class="component-title">&nbsp;</span>
	<span class="component-desc" id="level-name-comment"><a href="javascript:AddNewWorld();">Add New Level</a> <?php if(strlen($world)>0) echo "<b><font color='Red'>Save Required!</font></b>"; ?></span>
	<span class="component-title">&nbsp;</span>
	<span class="component-desc" id="level-name-comment"><a href="javascript:UploadWorld();">Upload Level</a></span>
   </label>
 </div> 

<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title">level-seed</span>
  <input type="text" id="level-seed" name="level-seed" value="<?php echo $CONFIG_INI['level-seed']; ?>" size="50" />
 </label>
</div>

 <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title">level-type</span>
   <span class="component-desc">
    <select name="level-type" id="level-type" class="input-medium" >
     <option value="DEFAULT"    <?php echo ((strtoupper($CONFIG_INI['level-type'])=="DEFAULT" )?" SELECTED":"");     ?>>DEFAULT</option>
     <option value="FLAT"       <?php echo ((strtoupper($CONFIG_INI['level-type'])=="FLAT")?" SELECTED":"");         ?>>FLAT</option>
     <option value="LARGEBIOMES"<?php echo ((strtoupper($CONFIG_INI['level-type'])=="LARGEBIOMES" )?" SELECTED":""); ?>>LARGEBIOMES</option>
     <option value="AMPLIFIED"  <?php echo ((strtoupper($CONFIG_INI['level-type'])=="AMPLIFIED")?" SELECTED":"");    ?>>AMPLIFIED</option>
	 </select>
   </span>
   </label>
 </div> 


<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title">max-build-height</span>
  <input type="text" id="max-build-height" name="max-build-height" value="<?php echo $CONFIG_INI['max-build-height']; ?>" size="5" />
 </label>
</div>

<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title">max-players</span>
  <input type="text" id="max-players" name="max-players" value="<?php echo $CONFIG_INI['max-players']; ?>" size="5" />
 </label>
</div>

<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title">motd</span>
  <input type="text" id="motd" name="motd" value="<?php echo $CONFIG_INI['motd']; ?>" size="50" />
 </label>
</div>

   <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title">online-mode</span>
   <span class="component-desc">
    <select name="online-mode" id="online-mode" class="input-medium" >
     <option value="true"<?php echo ((strtolower($CONFIG_INI['online-mode'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($CONFIG_INI['online-mode'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 
 
    <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title">op-permission-level</span>
   <span class="component-desc">
    <select name="op-permission-level" id="op-permission-level" class="input-medium" OnChange="updateop(this.options[this.selectedIndex].value);">
     <option value="1"<?php echo ((strtolower($CONFIG_INI['op-permission-level'])=="1")?" SELECTED":""); ?>>1</option>
     <option value="2"<?php echo ((strtolower($CONFIG_INI['op-permission-level'])=="2")?" SELECTED":""); ?>>2</option>
	 <option value="3"<?php echo ((strtolower($CONFIG_INI['op-permission-level'])=="3")?" SELECTED":""); ?>>3</option>
	 <option value="4"<?php echo ((strtolower($CONFIG_INI['op-permission-level'])=="4")?" SELECTED":""); ?>>4</option>
    </select>
   </span>
   </label>
   <label class="nocheck clearfix">
	<span class="component-title">&nbsp;</span>
	<span class="component-desc" id="op-permission-level-comment"></span>
   </label>
 </div> 
 
<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title">player-idle-timeout</span>
  <input type="text" id="player-idle-timeout" name="player-idle-timeout" value="<?php echo $CONFIG_INI['player-idle-timeout']; ?>" size="5" />
 </label>
</div>

   <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title">pvp</span>
   <span class="component-desc">
    <select name="pvp" id="online-mode" class="input-medium" >
     <option value="true"<?php echo ((strtolower($CONFIG_INI['pvp'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($CONFIG_INI['pvp'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 
 
<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title">query.port</span>
  <input type="text" id="query_port" name="query_port" value="<?php echo $CONFIG_INI['query_port']; ?>" size="5" />
 </label>
</div>
<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title">rcon.password</span>
  <input type="text" id="rcon_password" name="rcon_password" value="<?php echo $CONFIG_INI['rcon_password']; ?>" size="50" />
 </label>
</div>
<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title">rcon.port</span>
  <input type="text" id="rcon_port" name="rcon_port" value="<?php echo $CONFIG_INI['rcon_port']; ?>" size="5" />
 </label>
</div>
<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title">resource-pack</span>
  <input type="text" id="resource-pack" name="resource-pack" value="<?php echo $CONFIG_INI['resource-pack']; ?>" size="50" />
 </label>
</div>
<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title">server-ip</span>
  <input type="text" id="server-ip" name="server-ip" value="<?php echo $CONFIG_INI['server-ip']; ?>" size="50" />
 </label>
</div>
<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title">server-name</span>
  <input type="text" id="server-name" name="server-name" value="<?php echo $CONFIG_INI['server-name']; ?>" size="50" />
 </label>
</div>
<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title">server-port</span>
  <input type="text" id="server-port" name="server-port" value="<?php echo $CONFIG_INI['server-port']; ?>" size="5" />
 </label>
</div>

 <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title">snooper-enabled</span>
   <span class="component-desc">
    <select name="snooper-enabled" id="online-mode" class="input-medium" >
     <option value="true"<?php echo ((strtolower($CONFIG_INI['snooper-enabled'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($CONFIG_INI['snooper-enabled'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 
 
  <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title">spawn-animals</span>
   <span class="component-desc">
    <select name="spawn-animals" id="spawn-animals" class="input-medium" >
     <option value="true"<?php echo ((strtolower($CONFIG_INI['spawn-animals'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($CONFIG_INI['spawn-animals'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 
  <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title">spawn-monsters</span>
   <span class="component-desc">
    <select name="spawn-monsters" id="spawn-monsters" class="input-medium" >
     <option value="true"<?php echo ((strtolower($CONFIG_INI['spawn-monsters'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($CONFIG_INI['spawn-monsters'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 
  <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title">spawn-npcs</span>
   <span class="component-desc">
    <select name="spawn-npcs" id="spawn-npcs" class="input-medium" >
     <option value="true"<?php echo ((strtolower($CONFIG_INI['spawn-npcs'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($CONFIG_INI['spawn-npcs'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 
<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title">spawn-protection</span>
  <input type="text" id="spawn-protection" name="spawn-protection" value="<?php echo $CONFIG_INI['spawn-protection']; ?>" size="5" />
 </label>
</div>
<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title">view-distance</span>
  <input type="text" id="view-distance" name="view-distance" value="<?php echo $CONFIG_INI['view-distance']; ?>" size="5" />
 </label>
</div>
 
  <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title">white-list</span>
   <span class="component-desc">
    <select name="white-list" id="white-list" class="input-medium" >
     <option value="true"<?php echo ((strtolower($CONFIG_INI['white-list'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($CONFIG_INI['white-list'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 
 
<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title">texture-pack</span>
  <input type="text" id="texture-pack" name="texture-pack" value="<?php echo $CONFIG_INI['texture-pack']; ?>" size="50" />
 </label>
</div>

 <div class="component-group-save">
  <input type="submit" class="btn config_submitter" value="Save Changes" />
  </div><br />
</fieldset>


</div><!-- /component-group1 //-->
 </div><!-- /config-components -->
 </form>
 </div>
</div>
<center><b>Hint!:</b> For Creative mode, set difficulty=Easy and gamemode=Creative</center>
   </DIV><!-- /content -->
  </DIV><!-- /contentWrapper -->
   <FOOTER>
   <DIV class="container footer"><B>Minecraft Config</b></DIV><!-- /container footer -->
   </FOOTER>
 </BODY>
</HTML> 

<SCRIPT type="text/javascript">
function AddNewWorld()
{
	var world=prompt("Enter the name of your new World", "My_World");
		
	if (world!=null && world!="")
	{
		window.location = "addworld.php?world="+world;
	}
}
</SCRIPT>

<SCRIPT type="text/javascript">
function UploadWorld()
{
	window.location = "jupload/index.php";
}
</SCRIPT>

<SCRIPT type="text/javascript">
function DownloadWorld()
{
	var e = document.getElementById("level-name");
	var path = e.options[e.selectedIndex].value;	
	//alert("volume1/@appstore/Minecraft/"+strUser);

	window.location = "pack.php?path="+path;
}
</SCRIPT>


<SCRIPT type="text/javascript">
function updateop(val)
{
	val = parseInt(val);
	
	switch (val)
	{
		case 1  : val = "Ops can bypass spawn protection."; 																				break;
		case 2  : val = "Ops can use /clear, /difficulty, /effect, /gamemode, /gamerule, /give, and /tp, and can edit command blocks.";  break;
		case 3  : val = "Ops can use /ban, /deop, /kick, and /op."; 																		break;
		case 4  : val = "Ops can use /stop.";break;
		default : val = "";		break;
	}

	document.getElementById('op-permission-level-comment').innerHTML = val;
}
</SCRIPT>
 
<SCRIPT type="text/javascript">
 var frmvalidator  = new Validator("configForm");

 frmvalidator.addValidation("max-build-height","numeric");
 frmvalidator.addValidation("max-build-height","maxlen=3");
 frmvalidator.addValidation("max-build-height","lt=257"); 
 frmvalidator.addValidation("max-build-height","req", "max-build-height, Value should be between 1 and 256");
 
 frmvalidator.addValidation("max-players","numeric");
 frmvalidator.addValidation("max-players","maxlen=3");
 frmvalidator.addValidation("max-players","lt=101"); 
 frmvalidator.addValidation("max-players","req", "max-players, Value should be between 1 and 100");
 
 frmvalidator.addValidation("player-idle-timeout","numeric");
 frmvalidator.addValidation("player-idle-timeout","maxlen=2");
 frmvalidator.addValidation("player-idle-timeout","lt=61"); 
 frmvalidator.addValidation("player-idle-timeout","req", "player-idle-timeout, Value should be between 1 and 60");
 
 frmvalidator.addValidation("query.port","numeric");
 frmvalidator.addValidation("query.port","maxlen=5");
 frmvalidator.addValidation("query.port","lt=65536"); 
 frmvalidator.addValidation("query.port","req", "query_port, Value should be between 1 and 65535");
 
 frmvalidator.addValidation("rcon.port","numeric");
 frmvalidator.addValidation("rcon.port","maxlen=5");
 frmvalidator.addValidation("rcon.port","lt=65536"); 
 frmvalidator.addValidation("rcon.port","req", "rcon_port, Value should be between 1 and 65535");
 
 frmvalidator.addValidation("server.port","numeric");
 frmvalidator.addValidation("server.port","maxlen=5");
 frmvalidator.addValidation("server.port","lt=65536"); 
 frmvalidator.addValidation("server.port","req", "server.port, Value should be between 1 and 65535");
 
 frmvalidator.addValidation("spawn-protection","numeric");
 frmvalidator.addValidation("spawn-protection","maxlen=3");
 frmvalidator.addValidation("spawn-protection","lt=101"); 
 frmvalidator.addValidation("spawn-protection","req", "view-distance, Value should be between 1 and 100");
 
 frmvalidator.addValidation("view-distance","numeric");
 frmvalidator.addValidation("view-distance","maxlen=3");
 frmvalidator.addValidation("view-distance","lt=101"); 
 frmvalidator.addValidation("view-distance","req", "view-distance, Value should be between 1 and 100"); 
</SCRIPT>