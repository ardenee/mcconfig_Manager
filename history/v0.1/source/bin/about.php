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
 
   <DIV id=SubMenu><SPAN><A HREF="index.php">Home</A> | <A target="_Blank" HREF="http://minecraft.gamepedia.com/Server.properties">Help</A> | <A HREF="log.php">Minecraft Log</A></SPAN></DIV>
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
		   <span>mcconfig is a very basic tool for basic management of your Minecraft server on your Synology system.<BR><BR>
		   
		   <h1>Limits</h1>There are quite a few limitations such as...<BR><BR>
		   You need to either stop the Minecraft server before saving your changes or restart the server after saving your settings for thm to take effect. The script cannot do this for you due to permission restrictions. Likewise it cannot start the server after the edit. I had hopped to have this feature but don't have the time or resources to try and get this working just yet.<BR><BR>
		   When you upload your level, you have to set the owner of the files afterwards, again, due to permission restrictions, mmconfig cannot do this for you just yet.</span>
		   <BR><BR>
		   
		  <span><h1>Functions</h1>
		  Some of the nice things mcconfig can do are<br><br>
		  Change server settings<BR>
		  Upload Levels<BR>
		  Create New Levels<br>
		  Download Levels<BR>
		  Display the log file since last run</span><BR><BR>
		  
		  <span><h1>Updates</h1>
		  Planned updates are<BR><BR>

		  Edit the ops file<BR>
		  Edit the while list file<BR>
		  Ban and unban players/ips<br>
		  and remove the 2 above limits!</span><BR><BR>
		  
		  <span><h1>Support</h1>
		  If you know how to start and stop packages via php on a Synology system please do let me know and we can remove the first limitation.<BR><BR>Same goes for using chown and chgrp (or even via shell_exec) within php to change the ownership of files in the default app store of Synology systems ('/volume1/@appstore/Minecraft'), as Minecraft keeps everything in here instead of say the public share, it's really restricted!</span>
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
   <DIV class="container footer"><B>Minecraft Config</b></DIV><!-- /container footer -->
   </FOOTER>
 </BODY>
</HTML> 