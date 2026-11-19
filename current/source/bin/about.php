<?php
$CONFIG_INI = parse_ini_file("settings.ini", true, INI_SCANNER_RAW);
$parts = explode("|", $CONFIG_INI["Config"]["apps"]);
$game = $CONFIG_INI['Config']['selected'];
$path       = $CONFIG_INI[$game]['folder_path'];

?><!DOCTYPE HTML>
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
 <BODY>
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
  <h1 class="title"><B>About mcconfig</B><h1>
  <div id="config">
   <div id="config-content">
     <div id="config-components">
      <div id="core-component-group" class="component-group clearfix">
		<div id="config">
		 <div id="config-content">	
		<fieldset class="component-group-list">
		 <div class="field-pair">
		  <label class="nocheck clearfix" for="name_presets">
		   <span>mcconfig is a very basic tool for basic management of your Minecraft/Craftbukkit server on your Synology system.<BR><BR>
		   
		   <h1>Limits</h1>There are quite a few limitations such as...<BR><BR>
		   You need to either stop the Minecraft/Craftbukkit server before saving your changes or restart the server after saving your settings for the changes to take effect. The script cannot do this for you due to permission restrictions. Likewise it cannot start the server after the edit. I had hopped to have this feature but don't have the time or resources to try and get this working just yet.<BR><BR>
		   When you upload your level, you have to set the owner of the files afterwards, again, due to permission restrictions (chmod and chown), mmconfig cannot do this for you just yet.</span>
		   <BR><BR>
		   
		  <span><h1>Functions</h1>
		  Some of the nice things mcconfig can do are<br><br>
		  Change server settings<BR>
		  Upload Levels<BR>
		  Create New Levels<br>
		  Download Levels<BR>
		  Display the log file since last run<BR>
		  Edit Ops<br>
		  Edit Whitelist<br>
		  Edit Bans, IP and Player bans<br>
		  Upgrade/Downgrade your server jar file<br>
		  Feedback Option<br>
		  Works with Craftbukkit now too!</span><BR><BR>
		  
		  <span><h1>Updates</h1>
		  Planned updates are<BR><BR>
		  Pre 1.7.5, (UUID's implemented afterwards and are not fully supported. Limited functionality here. To be looked into for version 0.3!)<BR>
		  <font color=green>Done!</font> - Edit the ops file<BR>
		  <font color=green>Done!</font> - Edit the while list file<BR>
		  <font color=green>Done!</font> - Ban and unban players/ips<br>
		  <font color=green>Done!</font> - Upgrade/Downgrade your server jar file<br>
		  <font color=green>Done!</font> - New logs location for newer versions<BR>
		  and remove the 2 above limits!</span><BR><BR>
		  
		  <span><h1>Support</h1>
		  If you know how to start and stop packages via php on a Synology system please do let me know and we can remove the first limitation.<BR><BR>Same goes for using chown and chmod (or even via shell_exec) within php to change the ownership of files in the default app store of Synology systems ('/volume1/@appstore/Minecraft', '/volume1/@appstore/Craftbukkit'), as Minecraft/Craftbukkit keeps everything in here instead of say the public share, it's really restricted!</span>
		  </label>
		 </div>
		 </fieldset>
         </div><!-- /component-group1 //-->
        </div><!-- /component-group1 //-->
       </div><!-- /component-group1 //-->
      </div><!-- /config-components -->
     </div>
    </div>
   </DIV><!-- /content -->
  </DIV><!-- /contentWrapper -->
   <FOOTER>
   <DIV class="container footer"><B>mcconfig About</b></DIV><!-- /container footer -->
   </FOOTER>
 </BODY>
</HTML> 

<SCRIPT type="text/javascript">
function UpdateGame(selected)
{
	window.location = "updateselectedgame.php?game="+selected;	
}
</SCRIPT>