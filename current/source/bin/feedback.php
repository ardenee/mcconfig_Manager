<?php
$CONFIG_INI = parse_ini_file("settings.ini", true, INI_SCANNER_RAW);
$parts = explode("|", $CONFIG_INI["Config"]["apps"]);
$game  = $CONFIG_INI['Config']['selected'];
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
  <h1 class="title">Feedback<h1>
  <div id="config">
   <div id="config-content">
    <form id="feedback" action="mail-feedback.php" method="post">
     <div id="config-components">
      <div id="core-component-group1" class="component-group clearfix">
	  
       <div class="component-group-desc">
        <h3>Feedback by Email</h3>
       </div>	   
       <fieldset class="component-group-list">
        <div class="field-pair">
		 <label class="nocheck clearfix" for="user">
		  <span class="component-title">Your Email (optional)</span>
		  <input type="text" name="from" id="from" value="anonymous" size="53"><BR>
		  Note, if you'd like a reply, I can't reply if <b>Your Email</b> is left as anonymous. 
		 </label>
		</div>
       </fieldset>	   
      
	  </div>
      <DIV id="summary" class="align-left">

	   <label class="nocheck clearfix">
		<span class="component-title">Message</span>
		<textarea name="message" rows=15 cols=127></textarea>
	   </label>	   

	   <label class="nocheck clearfix">
		<span><font size=1>Please note that all feedback, positave and negitave is welcome. What would you like to see in dup in the future? Have you spotted any buys etc.<BR><BR>
			 
		Thanks!<br>arden</font>
		</span>

	   <div class="component-group-save">
         <input type="Submit" class="btn config_submitter" value="Send Feedback" />
	   </div>
		</label>
		
	  </div> 
     </div><!-- /config-components -->
	</form> 
   </div><!-- /config-content -->
  </div><!-- /config -->
 </DIV><!-- /content -->
</DIV><!-- /contentWrapper -->
  <FOOTER>
   <DIV class="container footer"><B>mcconfig Feedback</B></DIV><!-- /container footer -->
  </FOOTER>	
 </BODY>
</HTML> 

<SCRIPT type="text/javascript">
function UpdateGame(selected)
{
	window.location = "updateselectedgame.php?game="+selected;	
}
</SCRIPT>