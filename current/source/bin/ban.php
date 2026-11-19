<?php
$CONFIG_INI = parse_ini_file("settings.ini", true, INI_SCANNER_RAW);
$parts = explode("|", $CONFIG_INI["Config"]["apps"]);
$game  = $CONFIG_INI['Config']['selected'];
$path  = $CONFIG_INI[$game]['folder_path'];
$sp = $CONFIG_INI[$game]['string_sepseator'];
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
<h1 class="title">Bans - '<?php echo $game; ?>'</h1>
<div id="config">
 <div id="config-content"> 
 <h1 class="title">Players</h1>
 
 
 
<form id="delbanedplayer" name="delbanedplayer" method="post" action="deletebanplayer.php">
<?php
$newer = "";

if(!is_file($CONFIG_INI[$game]['file_banplayers']))
	$newer = ".converted"; // required for newer versions of Minecraft.

$bannedplayers = @file_get_contents($CONFIG_INI[$game]['file_banplayers'].$newer); 
$bannedplayers = array_unique(explode("\n", $bannedplayers));

echo "<table border=0 width=100% class=\"one\">\n";
echo "<tr class=a><td class=\"a\">&nbsp;</td><td class=a><b>Name</b></td><td class=\"a\"><b>Ban Date</b></td><td class=\"a\"><b>Banned By</b></td><td class=\"a\"><b>Until</b></td><td class=\"a\"><b>Reason</b></td></tr>\n";
		
foreach($bannedplayers as $key=>$val)
{
	if(strlen($val)==0)
		continue;
	
    if(!strstr($val, "# "))
	{
		$strdata = explode($sp, $val);	
		echo "<tr><td class=\"a\"><input type=checkbox name=\"".$strdata[0]."\" id=\"".$strdata[0]."\" value=\"".trim($strdata[0])."\"></td><td class=\"a\">".$strdata[0]."</td><td class=\"a\">".$strdata[1]."</td><td class=\"a\">".$strdata[2]."</td><td class=\"a\">".$strdata[3]."</td><td class=\"a\">".$strdata[4]."</td></tr>\n";
	}
}

echo "</table>\n";
 ?>
	<BR>
     <input type="button" class="btn config_submitter" value="Delete Selected"   Onclick="javascript:ProcessBan(delbanedplayer);"/> 
</form>	 
	<hr>  
<form id="addbannedplayer" name="addbannedplayer" method="post" action="addbanplayer.php">
	<table border=0>
	 <tr><td class=\"a\">Name:</td><td class=\"a\"><input type=text name=newplayer> Name of player to Ban.</td></tr>
	 <tr><td class=\"a\">Banned By:</td><td class=\"a\"><input type=text name=newadmin value=root> Your name.</td></tr>
	 <tr><td class=\"a\">Until:</td><td class=\"a\"><input type=text name=newuntil readonly class="tcal"> Leave blank for permanent Ban.</td></tr>
	 <tr><td class=\"a\">For:</td><td class=\"a\"><input type=text name=newfor> Reason for the Ban.</td></tr>
	</table>
     <BR><BR>
     <input type="button" class="btn config_submitter" value="Add Banned Player" Onclick="javascript:ProcessBan(addbannedplayer);"/> 
</form>
	 
 
 
 <BR><BR>
 
<h1 class="title">IP's</h1>
<form id="delbanedip" name="delbanedip" method="post" action="deletebanip.php">
<?php
$newer = "";

if(!is_file($CONFIG_INI[$game]['file_banips']))
	$newer = ".converted"; // required for newer versions of Minecraft.
	
$bannedplayers = @file_get_contents($CONFIG_INI[$game]['file_banips'].$newer); 
$bannedplayers = array_unique(explode("\n", $bannedplayers));

echo "<table border=0 width=100%>\n";
echo "<tr><td class=\"a\">&nbsp;</td><td class=\"a\"><b>Name</b></td><td class=\"a\"><b>Ban Date</b></td><td class=\"a\"><b>Banned By</b></td><td class=\"a\"><b>Until</b></td><td class=\"a\"><b>Reason</b></td></tr>\n";
		
foreach($bannedplayers as $key=>$val)
{
	if(strlen($val)==0)
		continue;
	
    if(!strstr($val, "# "))
	{
		$strdata = explode($sp, $val);	
		echo "<tr><td class=\"a\"><input type=checkbox name=\"".$strdata[0]."\" id=\"".$strdata[0]."\" value=\"".trim($strdata[0])."\"></td><td class=\"a\">".$strdata[0]."</td><td class=\"a\">".$strdata[1]."</td><td class=\"a\">".$strdata[2]."</td><td class=\"a\">".$strdata[3]."</td><td class=\"a\">".$strdata[4]."</td></tr>\n";
	}
}

echo "</table>\n";
 ?>
	<BR>
     <input type="button" class="btn config_submitter" value="Delete Selected"   Onclick="javascript:ProcessBan(delbanedip);"/> 
</form>	 
	<hr>  
<form id="addbannedip" name="addbannedip" method="post" action="addbanip.php">
	<table border=0>
	 <tr><td class=\"a\">Name:</td><td class=\"a\"><input type=text name=newplayer> Name of player to Ban.</td></tr>
	 <tr><td class=\"a\">Banned By:</td><td class=\"a\"><input type=text name=newadmin value=root> Your name.</td></tr>
	 <tr><td class=\"a\">Until:</td><td class=\"a\"><input type=text name=newuntil readonly class="tcal"> Leave blank for permanent Ban.</td></tr>
	 <tr><td class=\"a\">For:</td><td class=\"a\"><input type=text name=newfor> Reason for the Ban.</td></tr>
	</table>
     <BR><BR>
     <input type="button" class="btn config_submitter" value="Add Banned IP" Onclick="javascript:ProcessBan(addbannedip);"/> 
</form>
 
     </div>
    </div>
   </DIV><!-- /content -->
  </DIV><!-- /contentWrapper -->
   <FOOTER>
   <DIV class="container footer"><B>mcconfig Bans</b></DIV><!-- /container footer -->
   </FOOTER>
 </BODY>
</HTML> 

<SCRIPT type="text/javascript">
function ProcessBan(form)
{
    document.getElementById(form.id).submit();
}
</SCRIPT>

<script language="JavaScript">
	var cal1 = new calendar1(document.forms['banplayersform'].elements['newexpire']);
	cal1.year_scroll = true;
	cal1.time_comp = true;
</script>

<script language="JavaScript">
	var cal2 = new calendar1(document.forms['banipsform'].elements['newexpire']);
	cal2.year_scroll = true;
	cal2.time_comp = true;
</script>

<SCRIPT type="text/javascript">
function UpdateGame(selected)
{
	window.location = "updateselectedgame.php?game="+selected;	
}
</SCRIPT>

