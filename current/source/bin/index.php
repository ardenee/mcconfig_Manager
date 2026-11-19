<?php
$CONFIG_INI = parse_ini_file("settings.ini", true, INI_SCANNER_RAW);
$parts = explode("|", $CONFIG_INI["Config"]["apps"]);
$game  = $CONFIG_INI['Config']['selected'];
$worlds     = array();
$world      = "";
$path       = $CONFIG_INI[$game]['folder_path'];
$server_INI = parse_ini_file($CONFIG_INI[$game]['file_serversettings'], FALSE, INI_SCANNER_RAW);
$mcrunning = IsMCrunning($game);

if(!isset($server_INI["op-permission-level"]))
	$server_INI["op-permission-level"] = 1;
	
if(!isset($server_INI["player-idle-timeout"]))
	$server_INI["player-idle-timeout"] = 0;	

$results = scandir($path);

foreach ($results as $result) {
    if ($result === '.' or $result === '..') continue;

    if (is_dir($path . '/' . $result)) 
		$worlds[$result]=$result;
}

unset($worlds["logs"]);
unset($worlds["crash-reports"]);
unset($worlds["plugins"]);

if(isset($_GET["world"]))// level-name
{
	$world = $_GET["world"];
	$server_INI["level-name"] = $world;
}
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
   <SCRIPT TYPE="text/javascript" SRC="files/script.js"></SCRIPT>    
   <SCRIPT TYPE="text/javascript" SRC="files/gen_validatorv4.js"></SCRIPT>   
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
 <BODY onload="updateop(<?php echo $server_INI["op-permission-level"]; ?>);">
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


	<div id="contentWrapper">
	<div id="content">
<?php
if($mcrunning)
{
	echo "<span align=center><font color=red><b><center>$game is still running, saving won't take effect until it's been restarted!</center></b></font></span>";
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

<h1 class="title">server.properties - '<?php echo $game; ?>'</h1>

<div id="config">
 <div id="config-content">
  <form name="configForm" id="configForm" action="saveserversettings.php" method="post">
   <div id="config-components">
    <div id="core-component-group1" class="component-group clearfix">
    <div class="component-group-desc">
 	 <h3><?php echo $game; ?></h3>
 	 <p>Options</p>
 	</div> <!--component-group-desc-->
	
<fieldset class="component-group-list">
 <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title" title="Allows users to use flight on your server while in Survival mode, if they have a mod that provides flight installed.&#10;With allow-flight enabled griefers will possibly be more common, because it will make their work easier. In Creative mode this has no effect.&#10;&#10;false - Flight is not allowed (players in air for at least 5 seconds will be kicked).&#10;true - Flight is allowed, and used if the player has a fly mod installed.">allow-flight</span>
   <span class="component-desc">
    <select name="allow-flight" id="allow-flight" class="input-medium">
     <option value="true"<?php echo ((strtolower($server_INI['allow-flight'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($server_INI['allow-flight'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div>
 
  <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title" title="Allows players to travel to the Nether.&#10;&#10;false - Nether portals will not work.&#10;true - The server will allow portals to send players to the Nether.">allow-nether</span>
   <span class="component-desc">
    <select name="allow-nether" id="allow-nether" class="input-medium" >
     <option value="true"<?php echo ((strtolower($server_INI['allow-nether'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($server_INI['allow-nether'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div>
 
  <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title" title="Allows server to announce when a player gets an achievement.">announce-player-achievements</span>
   <span class="component-desc">
    <select name="announce-player-achievements" id="announce-player-achievements" class="input-medium" >
     <option value="true"<?php echo ((strtolower($server_INI['announce-player-achievements'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($server_INI['announce-player-achievements'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 

 
   <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title" title="Defines the difficulty (such as damage dealt by mobs and the way hunger and poison affects players) of the server.&#10;&#10;0 - Peaceful&#10;1 - Easy&#10;2 - Normal&#10;3 - Hard">difficulty</span>
   <span class="component-desc">
    <select name="difficulty" id="difficulty" class="input-medium" >
     <option value="0"<?php echo ((strtolower($server_INI['difficulty'])=="0")?" SELECTED":""); ?>>Peaceful</option>
     <option value="1"<?php echo ((strtolower($server_INI['difficulty'])=="1")?" SELECTED":""); ?>>Easy</option>
	 <option value="2"<?php echo ((strtolower($server_INI['difficulty'])=="2")?" SELECTED":""); ?>>Normal</option>
	 <option value="3"<?php echo ((strtolower($server_INI['difficulty'])=="3")?" SELECTED":""); ?>>Hard</option>
    </select>
   </span>
   </label>
 </div> 
 
 
  <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title" title="Enables GameSpy4 protocol server listener. Used to get information about server.">enable-query</span>
   <span class="component-desc">
    <select name="enable-query" id="enable-query" class="input-medium" >
     <option value="true"<?php echo ((strtolower($server_INI['enable-query'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($server_INI['enable-query'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 
   <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title" title="Enables remote access to the server console.">enable-rcon</span>
   <span class="component-desc">
    <select name="enable-rcon" id="enable-rcon" class="input-medium" >
     <option value="true"<?php echo ((strtolower($server_INI['enable-rcon'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($server_INI['enable-rcon'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 
   <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title" title="Enables command blocks">enable-command-block</span>
   <span class="component-desc">
    <select name="enable-command-block" id="enable-command-block" class="input-medium" >
     <option value="true"<?php echo ((strtolower($server_INI['enable-command-block'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($server_INI['enable-command-block'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 
   <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title" title="Force players to join in the default game mode.&#10;&#10;false - Players will join in the gamemode they left in.&#10;true - Players will always join in the default gamemode.">force-gamemode</span>
   <span class="component-desc">
    <select name="force-gamemode" id="force-gamemode" class="input-medium" >
     <option value="true"<?php echo ((strtolower($server_INI['force-gamemode'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($server_INI['force-gamemode'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 
 

 <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title" title="Defines the mode of gameplay.&#10;&#10;0 - Survival&#10;1 - Creative&#10;2 - Adventure&#10;3 - Spectator">gamemode</span>
   <span class="component-desc">
    <select name="gamemode" id="gamemode" class="input-medium" >
     <option value="0"<?php echo ((strtolower($server_INI['gamemode'])=="0" )?" SELECTED":""); ?>>Survival</option>
     <option value="1"<?php echo ((strtolower($server_INI['gamemode'])=="1")?" SELECTED":""); ?>>Creative</option>
	 <option value="2"<?php echo ((strtolower($server_INI['gamemode'])=="2")?" SELECTED":""); ?>>Adventure</option>
    </select>
   </span>
   </label>
 </div> 
 
 
   <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title" title="Defines whether structures (such as villages) will be generated.&#10;&#10;false - Structures will not be generated in new chunks.&#10;true - Structures will be generated in new chunks.&#10;&#10;Note: Dungeons and Nether Fortresses will still generate if this is set to false.">generate-structures</span>
   <span class="component-desc">
    <select name="generate-structures" id="generate-structures" class="input-medium" >
     <option value="true"<?php echo ((strtolower($server_INI['generate-structures'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($server_INI['generate-structures'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 

<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title" title="The settings used to customize Superflat world generation. See Superflat for possible settings and examples.">generator-settings</span>
  <input type="text" id="generator-settings" name="generator-settings" value="<?php echo $server_INI['generator-settings']; ?>" size="50" />
 </label>
</div>


 <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title" title="If set to true, players will be permanently banned if they die.">hardcore</span>
   <span class="component-desc">
    <select name="hardcore" id="hardcore" class="input-medium" >
     <option value="true"<?php echo ((strtolower($server_INI['hardcore'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($server_INI['hardcore'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 

 <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title" title="The &#34;level-name&#34; value will be used as the world name and its folder name. You may also copy your saved game folder here, and change the name to the same as that folder's to load it instead.&#10;&#10;Characters such as ' (apostrophe) may need to be escaped by adding a backslash before them.">level-name</span>
   <span class="component-desc">
    <select name="level-name" id="level-name" class="input-medium" >
	<?php
		foreach($worlds as $key => $value)
			echo "<option value=\"$value\"".((($server_INI['level-name'])=="$value")?" SELECTED":"").">$value</option>\n";
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
  <span class="component-title" title="Add a seed for your world, as in Singleplayer.&#10;&#10;Some examples are: minecraft, 404, 1a2b3c.">level-seed</span>
  <input type="text" id="level-seed" name="level-seed" value="<?php echo $server_INI['level-seed']; ?>" size="50" />
 </label>
</div>

 <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title" title="Determines the type of map that is generated.&#10;&#10;DEFAULT - Standard world with hills, valleys, water, etc.&#10;FLAT - A flat world with no features, meant for building.&#10;LARGEBIOMES - Same as default but all biomes are larger.&#10;AMPLIFIED - Same as default but world-generation height limit is increased.">level-type</span>
   <span class="component-desc">
    <select name="level-type" id="level-type" class="input-medium" >
     <option value="DEFAULT"    <?php echo ((strtoupper($server_INI['level-type'])=="DEFAULT" )?" SELECTED":"");     ?>>DEFAULT</option>
     <option value="FLAT"       <?php echo ((strtoupper($server_INI['level-type'])=="FLAT")?" SELECTED":"");         ?>>FLAT</option>
     <option value="LARGEBIOMES"<?php echo ((strtoupper($server_INI['level-type'])=="LARGEBIOMES" )?" SELECTED":""); ?>>LARGEBIOMES</option>
     <option value="AMPLIFIED"  <?php echo ((strtoupper($server_INI['level-type'])=="AMPLIFIED")?" SELECTED":"");    ?>>AMPLIFIED</option>
	 </select>
   </span>
   </label>
 </div> 


<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title" title="The maximum height in which building is allowed. Terrain may still naturally generate above a low height limit.">max-build-height</span>
  <input type="text" id="max-build-height" name="max-build-height" value="<?php echo $server_INI['max-build-height']; ?>" size="5" />
 </label>
</div>

<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title" title="The maximum number of players that can play on the server at the same time. Note that if more players are on the server it will use more resources. Note also, op player connections are not supposed to count against the max players, but ops currently cannot join a full server. Extremely large values for this field result in the client-side user list being broken.">max-players</span>
  <input type="text" id="max-players" name="max-players" value="<?php echo $server_INI['max-players']; ?>" size="5" />
 </label>
</div>

<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title" title="This is the message that is displayed in the server list of the client, below the name.&#10;&#10;The MOTD does support color and formatting codes.&#10;If the MOTD is over 59 characters, the server list will likely report a communication error.">motd</span>
  <input type="text" id="motd" name="motd" value="<?php echo $server_INI['motd']; ?>" size="50" maxlength="59"/>
 </label>
</div>

   <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title" title="Server checks connecting players against minecraft's account database. Only set this to false if your server is not connected to the Internet. Hackers with fake accounts can connect if this is set to false! If minecraft.net is down or inaccessible, no players will be able to connect if this is set to true. Setting this variable to off purposely is called &#34;cracking&#34; a server, and servers that are presently with online mode off are called &#34;cracked&#34; servers, allowing players with unlicensed copies of Minecraft to join.&#10;&#10;true - Enabled. The server will assume it has an Internet connection and check every connecting player.&#10;false - Disabled. The server will not attempt to check connecting players.">online-mode</span>
   <span class="component-desc">
    <select name="online-mode" id="online-mode" class="input-medium" >
     <option value="true"<?php echo ((strtolower($server_INI['online-mode'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($server_INI['online-mode'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 
 
    <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title" title="Sets permission level for ops.&#10;&#10;1 - Ops can bypass spawn protection.&#10;2 - Ops can use /clear, /difficulty, /effect, /gamemode, /gamerule, /give, and /tp, and can edit command blocks.&#10;3 - Ops can use /ban, /deop, /kick, and /op.&#10;4 - Ops can use /stop.">op-permission-level</span>
   <span class="component-desc">
    <select name="op-permission-level" id="op-permission-level" class="input-medium" OnChange="updateop(this.options[this.selectedIndex].value);">
     <option value="1"<?php echo ((strtolower($server_INI['op-permission-level'])=="1")?" SELECTED":""); ?>>1</option>
     <option value="2"<?php echo ((strtolower($server_INI['op-permission-level'])=="2")?" SELECTED":""); ?>>2</option>
	 <option value="3"<?php echo ((strtolower($server_INI['op-permission-level'])=="3")?" SELECTED":""); ?>>3</option>
	 <option value="4"<?php echo ((strtolower($server_INI['op-permission-level'])=="4")?" SELECTED":""); ?>>4</option>
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
  <span class="component-title" title="If non-zero, players are kicked from the server if they are idle for more than that many minutes.&#10;Note: Idle time is reset when the server receives one of the following packets:&#10;&#10;102 (0x66) WindowClick&#10;108 (0x6c) ButtonClick&#10;130 (0x82) UpdateSign&#10;14 (0xe) BlockDig&#10;15 (0xf) Place&#10;16 (0x10) BlockItemSwitch&#10;18 (0x12) ArmAnimation&#10;19 (0x13) EntityAction&#10;205 (0xcd) ClientCommand&#10;3 (0x3) Chat&#10;7 (0x7) UseEntity">player-idle-timeout</span>
  <input type="text" id="player-idle-timeout" name="player-idle-timeout" value="<?php echo $server_INI['player-idle-timeout']; ?>" size="5" />
 </label>
</div>

   <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title" title="Enable PvP on the server. Players shooting themselves with arrows will only receive damage if PvP is enabled.&#10;Note: Indirect damage sources spawned by players (such as lava, fire, TNT and to some extent water, sand and gravel) will still deal damage to other players.&#10;&#10;true - Players will be able to kill each other.&#10;false - Players cannot kill other players (also known as Player versus Environment (PvE)).">pvp</span>
   <span class="component-desc">
    <select name="pvp" id="online-mode" class="input-medium" >
     <option value="true"<?php echo ((strtolower($server_INI['pvp'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($server_INI['pvp'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 
 
<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title" title="Sets the port for the query server (see enable-query).">query.port</span>
  <input type="text" id="query_port" name="query_port" value="<?php echo $server_INI['query_port']; ?>" size="5" />
 </label>
</div>
<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title" title="Sets the password to rcon.">rcon.password</span>
  <input type="text" id="rcon_password" name="rcon_password" value="<?php echo $server_INI['rcon_password']; ?>" size="50" />
 </label>
</div>
<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title" title="Sets the port to rcon.">rcon.port</span>
  <input type="text" id="rcon_port" name="rcon_port" value="<?php echo $server_INI['rcon_port']; ?>" size="5" />
 </label>
</div>
<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title" title="Server prompts client to download resource pack upon join. This link must be a direct link to the actual resource pack .zip file.">resource-pack</span>
  <input type="text" id="resource-pack" name="resource-pack" value="<?php echo $server_INI['resource-pack']; ?>" size="50" />
 </label>
</div>
<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title" title="Set this if you want the server to bind to a particular IP. It is strongly recommended that you leave server-ip blank!&#10;Set to blank, or the IP you want your server to run (listen) on.">server-ip</span>
  <input type="text" id="server-ip" name="server-ip" value="<?php echo $server_INI['server-ip']; ?>" size="50" />
 </label>
</div>
<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title" title="The name of your server.">server-name</span>
  <input type="text" id="server-name" name="server-name" value="<?php echo $server_INI['server-name']; ?>" size="50" />
 </label>
</div>
<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title" title="Changes the port the server is hosting (listening) on. This port must be forwarded if the server is hosted in a network using NAT (If you have a home router/firewall).">server-port</span>
  <input type="text" id="server-port" name="server-port" value="<?php echo $server_INI['server-port']; ?>" size="5" />
 </label>
</div>

 <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title" title="Sets whether the server sends snoop data regularly to http://stats.minecraft.net.&#10;&#10;false - disable snooping.&#10;true - enable snooping.">snooper-enabled</span>
   <span class="component-desc">
    <select name="snooper-enabled" id="online-mode" class="input-medium" >
     <option value="true"<?php echo ((strtolower($server_INI['snooper-enabled'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($server_INI['snooper-enabled'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 
 
  <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title" title="Determines if animals will be able to spawn.&#10;&#10;true - Animals spawn as normal.&#10;false - Animals will immediately vanish.&#10;&#10;Tip: if you have major lag, turn this off/set to false.">spawn-animals</span>
   <span class="component-desc">
    <select name="spawn-animals" id="spawn-animals" class="input-medium" >
     <option value="true"<?php echo ((strtolower($server_INI['spawn-animals'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($server_INI['spawn-animals'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 
  <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title" title="Determines if monsters will be spawned.&#10;&#10;true - Enabled. Monsters will appear at night and in the dark.&#10;false - Disabled. No monsters.&#10;&#10;This does nothing if difficulty = 0 (peaceful) Unless your difficulty is not set to 0, when a monster can still spawn from a Monster Spawner. Tip: if you have major lag, turn this off/set to false.">spawn-monsters</span>
   <span class="component-desc">
    <select name="spawn-monsters" id="spawn-monsters" class="input-medium" >
     <option value="true"<?php echo ((strtolower($server_INI['spawn-monsters'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($server_INI['spawn-monsters'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 
  <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title" title="Determines if villagers will be spawned.&#10;&#10;true - Enabled. Villagers will spawn.&#10;false - Disabled. No villagers.">spawn-npcs</span>
   <span class="component-desc">
    <select name="spawn-npcs" id="spawn-npcs" class="input-medium" >
     <option value="true"<?php echo ((strtolower($server_INI['spawn-npcs'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($server_INI['spawn-npcs'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 
<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title" title="Determines the radius of the spawn protection. Setting this to 0 will not disable spawn protection. 0 will protect the single block at the spawn point. 1 will protect a 3x3 area centered on the spawn point. 2 will protect 5x5, 3 will protect 7x7, etc. This option is not generated on the first server start and appears when the first player joins. If there are no ops set on the server, the spawn protection will be disabled automatically.">spawn-protection</span>
  <input type="text" id="spawn-protection" name="spawn-protection" value="<?php echo $server_INI['spawn-protection']; ?>" size="5" />
 </label>
</div>
<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title" title="Sets the amount of world data the server sends the client, measured in chunks in each direction of the player (radius, not diameter). It determines the server-side viewing distance. The &#34;Far&#34; viewing distance is 16 chunks, sending 1089 total chunks (the amount of chunks that the server will load can be seen in the debug screen). &#34;Normal&#34; view distance is 8, for 289 chunks.&#10;&#10;10 is the default/recommended. If you have major lag, reduce this value.">view-distance</span>
  <input type="text" id="view-distance" name="view-distance" value="<?php echo $server_INI['view-distance']; ?>" size="5" />
 </label>
</div>
 
  <div class="field-pair">
  <label class="nocheck clearfix" for="name_presets">
   <span class="component-title" title="Enables a whitelist on the server.&#10;With a whitelist enabled, users not on the whitelist will be unable to connect. Intended for private servers, such as those for real-life friends or strangers carefully selected via an application process, for example.&#10;&#10;Note - Ops are automatically white listed, and there is no need to add them to the whitelist.&#10;false - No white list is used.&#10;true - The file white-list.txt is used to generate the white list.">white-list</span>
   <span class="component-desc">
    <select name="white-list" id="white-list" class="input-medium" >
     <option value="true"<?php echo ((strtolower($server_INI['white-list'])=="true" )?" SELECTED":""); ?>>True</option>
     <option value="false"<?php echo ((strtolower($server_INI['white-list'])=="false")?" SELECTED":""); ?>>False</option>
    </select>
   </span>
   </label>
 </div> 
 
<div class="field-pair">
 <label class="nocheck clearfix">
  <span class="component-title" title="Server prompts client to download texture pack upon join. This link must be a direct link to the actual texture pack .zip file.">texture-pack</span>
  <input type="text" id="texture-pack" name="texture-pack" value="<?php echo $server_INI['texture-pack']; ?>" size="50" />
 </label>
</div>

 <div class="component-group-save">
  <input type="submit" class="btn config_submitter" value="Save Changes" <?php echo ($mcrunning?"disabled":""); ?> />
  </div><br />
</fieldset>


</div><!-- /component-group1 //-->
 </div><!-- /config-components -->
 </form>
 </div>
</div>
<center><b>Hint!:</b> For Creative mode, set <u>difficulty=Easy</u> and <u>gamemode=Creative</u></center>
   </DIV><!-- /content -->
  </DIV><!-- /contentWrapper -->
   <FOOTER>
   <DIV class="container footer"><B>mcconfig Home</b></DIV><!-- /container footer -->
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
function UpdateGame(selected)
{
	window.location = "updateselectedgame.php?game="+selected;	
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
 
 frmvalidator.addValidation("motd","string");
 frmvalidator.addValidation("motd","maxlen=59");
 
 frmvalidator.addValidation("view-distance","numeric");
 frmvalidator.addValidation("view-distance","maxlen=3");
 frmvalidator.addValidation("view-distance","lt=101"); 
 frmvalidator.addValidation("view-distance","req", "view-distance, Value should be between 1 and 100"); 
</SCRIPT>