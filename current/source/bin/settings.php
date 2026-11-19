<?php
$worlds     = array();
$world      = "";
$CONFIG_INI = parse_ini_file("settings.ini", true, INI_SCANNER_RAW);
$parts = explode("|", $CONFIG_INI["Config"]["apps"]);
$game = $CONFIG_INI['Config']['selected'];
$path = $CONFIG_INI[$game]['folder_path'];
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
  <SCRIPT TYPE="text/javascript" SRC="files/script.js"></SCRIPT>    
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
    <h1 class="title">mcconfig Settings</h1>
    <div id="config">
     <div id="config-content">
      <form name="configForm" id="configForm" action="saveinisettings.php" method="post">
      <div id="config-components">
       <div id="core-component-group1" class="component-group clearfix">
	   
        <ul class="tabs">	
         <li><a href="#">Config</a></li>	
<?php
		foreach($parts as $key => $value)
			echo "         <li><a href=\"#\">$value</a></li>\n";	
?>
        </ul> 
        <div class="panes">
         <div><center>
<?php  
			$sp = "<td>&nbsp;&nbsp;&nbsp;&nbsp;</td>\n";
			$CONFIG_INI["Config"]["path"] = getcwd();
			$CONFIG_INI["Config"]["tmp"]  = getcwd()."/tmp";
			echo "         <table with=100%>\n";
			echo "          <tr><td>mcconfigVersion:      </td>$sp<td> <input type=\"text\" name=\"Config[version]\" value=\"".$CONFIG_INI["Config"]["version"]."\" READONLY size=\"75\"></td></tr>\n";
			echo "          <tr><td>Managed Games:        </td>$sp<td> <input type=\"text\" name=\"Config[apps]\"    value=\"".$CONFIG_INI["Config"]["apps"]."\"    READONLY size=\"75\"></td></tr>\n";
			echo "          <tr><td>mcconfig root Folder: </td>$sp<td> <input type=\"text\" name=\"Config[pat]h\"    value=\"".$CONFIG_INI["Config"]["path"]."\"    READONLY size=\"75\"></td></tr>\n";
			echo "          <tr><td>mcconfig tmp Folder:  </td>$sp<td> <input type=\"text\" name=\"Config[tmp]\"     value=\"".$CONFIG_INI["Config"]["tmp"]."\"     READONLY size=\"75\"></td></tr>\n";
			echo "         </table>\n";
?>		
         </center></div>		
<?php
		foreach($parts as $key => $value)
		{
			$CONFIG_INI[$value]["name"] = strtolower($value);
			echo "         <div><center>\n";			
			echo "          <table with=100%>\n";
			echo "           <tr><td>Game Name:              </td>$sp<td> <input type=\"text\" name=\"".$value."[string_name]\"         id=\"".$value."_name\"         value=\"".$CONFIG_INI[$value]["string_name"]."\"         READONLY size=\"75\"></td></tr>\n";
			echo "           <tr><td>String Sepseator:       </td>$sp<td> <input type=\"text\" name=\"".$value."[string_sepseator]\"    id=\"".$value."_sepseator\"    value=\"".$CONFIG_INI[$value]["string_sepseator"]."\"             size=\"75\"></td></tr>\n";
			echo "           <tr><td>Upgrade URL:            </td>$sp<td> <input type=\"text\" name=\"".$value."[url_upgrade]\"         id=\"".$value."_upgrade\"      value=\"".$CONFIG_INI[$value]["url_upgrade"]."\"         READONLY size=\"75\"></td></tr>\n";
			echo "           <tr><td>Info URL:               </td>$sp<td> <input type=\"text\" name=\"".$value."[url_info]\"            id=\"".$value."_info\"         value=\"".$CONFIG_INI[$value]["url_info"]."\"                     size=\"75\"></td></tr>\n";
			echo "           <tr><td>Game Path:              </td>$sp<td> <input type=\"text\" name=\"".$value."[folder_path]\"         id=\"$value\"                  value=\"".$CONFIG_INI[$value]["folder_path"]."\"         READONLY size=\"75\"></td></tr>\n";
			echo "           <tr><td>Crash Report Folder:    </td>$sp<td> <input type=\"text\" name=\"".$value."[folder_crashreports]\" id=\"".$value."_crashreports\" value=\"".$CONFIG_INI[$value]["folder_crashreports"]."\" READONLY size=\"75\"></td></tr>\n";
			echo "           <tr><td>Plugin Folder:          </td>$sp<td> <input type=\"text\" name=\"".$value."[folder_plugins]\"      id=\"".$value."_plugins\"      value=\"".$CONFIG_INI[$value]["folder_plugins"]."\"      READONLY size=\"75\"></td></tr>\n";
			echo "           <tr><td>Log File:               </td>$sp<td> <input type=\"text\" name=\"".$value."[file_logs]\"           id=\"".$value."_logs\"         value=\"".$CONFIG_INI[$value]["file_logs"]."\"           READONLY size=\"75\"></td></tr>\n";
			echo "           <tr><td>Jar File:               </td>$sp<td> <input type=\"text\" name=\"".$value."[file_jar]\"            id=\"".$value."_jarfile\"      value=\"".$CONFIG_INI[$value]["file_jar"]."\"            READONLY size=\"75\"></td></tr>\n";	
			echo "           <tr><td>Ban IP's File:          </td>$sp<td> <input type=\"text\" name=\"".$value."[file_banips]\"         id=\"".$value."_banips\"       value=\"".$CONFIG_INI[$value]["file_banips"]."\"         READONLY size=\"75\"></td></tr>\n";
			echo "           <tr><td>Ban Players File:       </td>$sp<td> <input type=\"text\" name=\"".$value."[file_banplayers]\"     id=\"".$value."_banplayers\"   value=\"".$CONFIG_INI[$value]["file_banplayers"]."\"     READONLY size=\"75\"></td></tr>\n";
			echo "           <tr><td>Ops file:               </td>$sp<td> <input type=\"text\" name=\"".$value."[file_ops]\"            id=\"".$value."_ops\"          value=\"".$CONFIG_INI[$value]["file_ops"]."\"            READONLY size=\"75\"></td></tr>\n";
			echo "           <tr><td>Whitelist File:         </td>$sp<td> <input type=\"text\" name=\"".$value."[file_whitelist]\"      id=\"".$value."_whitelist\"    value=\"".$CONFIG_INI[$value]["file_whitelist"]."\"      READONLY size=\"75\"></td></tr>\n";
			echo "           <tr><td>Server Properties File: </td>$sp<td> <input type=\"text\" name=\"".$value."[file_serversettings]\" id=\"".$value."_settingsfile\" value=\"".$CONFIG_INI[$value]["file_serversettings"]."\" READONLY size=\"75\"></td></tr>\n";
			echo "           <tr><td>Config File:            </td>$sp<td> <input type=\"text\" name=\"".$value."[file_config]\"         id=\"".$value."_config\"       value=\"".$CONFIG_INI[$value]["file_config"]."\"         READONLY size=\"75\"></td></tr>\n";
			echo "           <tr><td>Permissions File:       </td>$sp<td> <input type=\"text\" name=\"".$value."[file_permissions]\"    id=\"".$value."_permissions\"  value=\"".$CONFIG_INI[$value]["file_permissions"]."\"    READONLY size=\"75\"></td></tr>\n";
			echo "           <tr><td>Help File:              </td>$sp<td> <input type=\"text\" name=\"".$value."[file_help]\"           id=\"".$value."_help\"         value=\"".$CONFIG_INI[$value]["file_help"]."\"           READONLY size=\"75\"></td></tr>\n";
			echo "           <tr><td>Commands File:          </td>$sp<td> <input type=\"text\" name=\"".$value."[file_commands]\"       id=\"".$value."_commands\"     value=\"".$CONFIG_INI[$value]["file_commands"]."\"       READONLY size=\"75\"></td></tr>\n";
			echo "           </table>\n";			
			echo "         </center></div>\n";		
		}		
?> 
        </div>
	    <div class="component-group-save">
	     <input type="submit" class="btn config_submitter" value="Save Changes" />
	    </div>
       </div><!-- /component-group1 //-->
      </div><!-- /config-components -->
      </form>
     </div>
    </div>
   </DIV><!-- /content -->
  </DIV><!-- /contentWrapper -->
 </BODY>
 
 <FOOTER>
  <DIV class="container footer"><B>mcconfig Settings</b></DIV><!-- /container footer -->
 </FOOTER>
 
</HTML> 


<script type="text/javascript" charset="utf-8">
<!--
$(document).ready(function(){
	$("ul.tabs").tabs("div.panes > div");
});

<?php
foreach($parts as $key => $value)
{
	echo "$(document).ready(function(){\n";
	echo "$('#$value').fileBrowser({ title: 'Select Database Directory' });\n";
	echo "});\n";
}
?>
//-->
</script>

<SCRIPT type="text/javascript">
function UpdateOthers(game)
{
	newv  = document.getElementById(game);
	
	newv2 = document.getElementById(game+'_logs');
	newv2.value = newv.value + '/' + newv2.value.replace(/^.*(\\|\/|\:)/, '');	
	newv2 = document.getElementById(game+'_jarfile');
	newv2.value = newv.value + '/' + newv2.value.replace(/^.*(\\|\/|\:)/, '');	
	newv2 = document.getElementById(game+'_banips');
	newv2.value = newv.value + '/' + newv2.value.replace(/^.*(\\|\/|\:)/, '');	
	newv2 = document.getElementById(game+'_banplayers');
	newv2.value = newv.value + '/' + newv2.value.replace(/^.*(\\|\/|\:)/, '');	
	newv2 = document.getElementById(game+'_ops');
	newv2.value = newv.value + '/' + newv2.value.replace(/^.*(\\|\/|\:)/, '');	
	newv2 = document.getElementById(game+'_whitelist');
	newv2.value = newv.value + '/' + newv2.value.replace(/^.*(\\|\/|\:)/, '');	
	newv2 = document.getElementById(game+'_settingsfile');
	newv2.value = newv.value + '/' + newv2.value.replace(/^.*(\\|\/|\:)/, '');	
	newv2 = document.getElementById(game+'_config');
	newv2.value = ((newv2.value.length>0) ? newv.value + '/' + newv2.value.replace(/^.*(\\|\/|\:)/, '') : "");	
	newv2 = document.getElementById(game+'_permissions');
	newv2.value = ((newv2.value.length>0) ? newv.value + '/' + newv2.value.replace(/^.*(\\|\/|\:)/, '') : "");	
	newv2 = document.getElementById(game+'_crashreports');
	newv2.value = ((newv2.value.length>0) ? newv.value + '/' + newv2.value.replace(/^.*(\\|\/|\:)/, '') : "");	
	newv2 = document.getElementById(game+'_plugins');
	newv2.value = newv.value + '/' + newv2.value.replace(/^.*(\\|\/|\:)/, '');
	newv2 = document.getElementById(game+'_help');
	newv2.value = ((newv2.value.length>0) ? newv.value + '/' + newv2.value.replace(/^.*(\\|\/|\:)/, '') : "");	
	newv2 = document.getElementById(game+'_commands');
	newv2.value = ((newv2.value.length>0) ? newv.value + '/' + newv2.value.replace(/^.*(\\|\/|\:)/, '') : "");			
}
</SCRIPT>

<SCRIPT type="text/javascript">
function UpdateGame(selected)
{
	window.location = "updateselectedgame.php?game="+selected;	
}
</SCRIPT>