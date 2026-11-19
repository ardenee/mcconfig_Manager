<?php
$path    = '/volume1/@appstore/Minecraft/';
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

<BODY>
 
   <DIV id=SubMenu><SPAN><A HREF="index.php">Home</A> | <A target="_Blank" HREF="http://minecraft.gamepedia.com/Server.properties">Help</A> | <A HREF="about.php">About</A> | <A HREF="log.php">Refresh Logs</A></SPAN></DIV>
  </HEADER>

<div id="contentWrapper">
<div id="content">
<h1 class="title">Server Log</h1>

<div id="config">
 <div id="config-content">

 <?php
 $data = @file_get_contents($path."/server.log");
 echo "<pre>";
 print_r($data);
 echo "</pre>";
 ?>
 
 
 </div>
</div>
   </DIV><!-- /content -->
  </DIV><!-- /contentWrapper -->
   <FOOTER>
   <DIV class="container footer"><B>Minecraft Logs</b></DIV><!-- /container footer -->
   </FOOTER>
 </BODY>
</HTML> 

