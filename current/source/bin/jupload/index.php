<?php
//We need a session !
session_start();
//Let's load the 'interesting' stuff ...  ;-)
include 'jupload.php';

$CONFIG_INI = parse_ini_file("../settings.ini", true, INI_SCANNER_RAW);
$parts = explode("|", $CONFIG_INI["Config"]["apps"]);
$game  = $CONFIG_INI['Config']['selected'];
$path  = $CONFIG_INI[$game]['folder_path'];


function handle_uploaded_files($juploadPhpSupportClass, $files) {
	return
		"<P>We are in the 'handle_uploaded_files' callback function, in the index.php script. To avoid double coding, we "
		. "just call the default behavior of the JUpload PHP class. Just replace this by your code...</P>"
		. $juploadPhpSupportClass->defaultAfterUploadManagement();
		;

}

$appletParameters = array(
		//Default value is ... maximum size for a file on the current FS. 2G is problably too much already.
        'maxFileSize' => '400M',
        //
        //In the sourceforge project structure, the applet jar file is one folder below. Default
        //configuration is ok, if wjhk.jupload.jar is in the same folder as the script containing this call.
        'archive' => 'wjhk.jupload.jar',
        //To manage, other jar files, like the ftp jar files if postURL is an FTP URL:
        //'archive' => 'wjhk.jupload.jar,jakarta-commons-oro.jar,jakarta-commons-net.jar',
        //
        //Default afterUploadURL displays the list of uploaded files above the applet (in the <!--JUPLOAD_FILES--> markers, see below)
        //You can use any page you want, to manage the uploaded files. Here is a sample, that also only shows the list of files.
        'afterUploadURL' => 'after_upload.php',
        //
        //This demo expects the md5sum to be sent by the applet. But the parameter is not mandatory
        //This value should be set to false (or the line commented), for big files, as md5 calculation
        //may be long  (Note this must be string and *not* boolean true/false)
        'sendMD5Sum' => 'true',
        //
        'debugLevel' => 0 // 100 disables redirect after upload, so we keep it below. This still gives a lot of information, in case of problem.
    );

$classParameters = array(
		//Files won't be stored on the server. Useful for first tests of the applet behavior ... and sourceforge demo site !
        'demo_mode' => false,
        //
        //Allow creation of subdirectories, when uploading several folders/files (drag and drop a folder on the applet to use it).
        'allow_subdirs' => true,
        //
        // The callbackAfterUploadManagement function will be called, once all files are uploaded, with the list
        //of uploaded files as an argument. See the above sample, and change it according to your needs.
        //'callbackAfterUploadManagement' => 'handle_uploaded_files',
        //
        //I work on windows. The default configuration is /var/tmp/jupload_test
        'destdir' => $path  //Where to store the files on the web
        //'errormail' => 'me@my.domain.org',
    );

////////////////////////////////////////////////////////////////////////////////////////////////////////
// Instantiate and initialize JUpload : integration of the applet in your web site.
$juploadPhpSupportClass = new JUpload($appletParameters, $classParameters);
////////////////////////////////////////////////////////////////////////////////////////////////////////



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
     <SCRIPT SRC="../files/html5.js"></SCRIPT>
   <![endif]--> 
   <LINK REL="shortcut icon" HREF="../images/mcconfig.png" />
   <LINK REL="stylesheet" TYPE="text/css" HREF="../files/bootstrap.css" /> 
   <LINK REL="stylesheet" TYPE="text/css" HREF="../files/jquery.pnotify.default.css" />  
   <LINK REL="stylesheet" TYPE="text/css" HREF="../files/jquery-ui-1.8.24.custom.css" />
   <LINK REL="stylesheet" TYPE="text/css" HREF="../files/jquery.qtip.css" />
   <LINK REL="stylesheet" TYPE="text/css" HREF="../files/style.css" /> 
   <LINK REL="stylesheet" TYPE="text/css" HREF="../files/imgs.css" /> 
   <LINK REL="stylesheet" TYPE="text/css" HREF="../files/tcal.css" />
   <SCRIPT TYPE="text/javascript" SRC="../files/tcal.js"></SCRIPT>   
   <SCRIPT TYPE="text/javascript" SRC="../files/jquery-1.7.2.min.js"></SCRIPT>
   <SCRIPT TYPE="text/javascript" SRC="../files/jquery-ui-1.8.24.custom.min.js"></SCRIPT>
   <SCRIPT TYPE="text/javascript" SRC="../files/bootstrap.min.js"></SCRIPT>
   <SCRIPT TYPE="text/javascript" SRC="../files/jquery.cookie.js"></SCRIPT>
   <SCRIPT TYPE="text/javascript" SRC="../files/jquery.cookiejar.js"></SCRIPT>
   <SCRIPT TYPE="text/javascript" SRC="../files/jquery.json-2.2.min.js"></SCRIPT>
   <SCRIPT TYPE="text/javascript" SRC="../files/jquery.selectboxes.min.js"></SCRIPT>
   <SCRIPT TYPE="text/javascript" SRC="../files/jquery.tablesorter-2.1.19.min.js"></SCRIPT>
   <SCRIPT TYPE="text/javascript" SRC="../files/jquery.tablesorter.widgets.min.js"></SCRIPT>
   <SCRIPT TYPE="text/javascript" SRC="../files/jquery.qtip-2012-04-26.min.js"></SCRIPT>
   <SCRIPT TYPE="text/javascript" SRC="../files/jquery.pnotify-1.2.0.min.js"></SCRIPT>
   <SCRIPT TYPE="text/javascript" SRC="../files/jquery.expand-1.3.8.js"></SCRIPT>  
   <SCRIPT TYPE="text/javascript" SRC="../files/jquery.form-2.92.js"></SCRIPT>  
   <SCRIPT TYPE="text/javascript" CHARSET="utf-8">
	    sbRoot = '/'; 
        top_image_html = '<IMG SRC="../images/top.gif" STYLE="width:31px; height:11px" alt="Jump to top" />'; 
   </SCRIPT>  
   <SCRIPT TYPE="text/javascript" SRC="../files/jquery.scrolltopcontrol-1.1.js"></SCRIPT>
   <SCRIPT TYPE="text/javascript" SRC="../files/ajaxNotifications.js"></SCRIPT>
   <SCRIPT TYPE="text/javascript" SRC="../files/script.js"></SCRIPT>    
   <SCRIPT TYPE="text/javascript" SRC="../files/gen_validatorv4.js"></SCRIPT>   
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
 <A HREF="../index.php">Home</A> | 
 <A HREF="../log.php">Logs</A> | 
 <A HREF="../ops.php">Ops</A> | 
 <A HREF="../ban.php">Ban</A> | 
 <A HREF="../whitelist.php">Whitelist</A> | 
 <A HREF="../jarupgrade.php">jar Upgrade</A> | 
 <A HREF="../settings.php">Settings</A> | 
 <A target="_Blank" HREF="http://minecraft.gamepedia.com/Server.properties">Help</A> | 
 <A HREF="../about.php">About</A> | 
 
    <select name="game" id="game" class="input-medium" OnChange="UpdateGame(this.options[this.selectedIndex].value);">
<?php
		foreach($parts as $key => $value)
			echo "        <option value=\"$value\"".(($game==$value)?" SELECTED":"").">$value</option>\n";
?>
    </select> |
	<A HREF="../feedback.php">Feedback</A> 
 </SPAN></DIV>
</HEADER>



<div id="contentWrapper">
<div id="content">
<?php
	echo "<BR>After uploading you will need to run the <b>chown</b> command via ssh to set the owner of the files you just uploaded.<BR><BR>";	
	echo "You can do this by stopping and restarting the <B>mcconfig</b> package within the Synology Package Centre as this updates the folders/files permissions or via ssh.<BR><BR>";
	echo "ssh command to Run: <u><i>chown -R ".strtolower($game).":users \"$path/<font color=red>**Folder you just uploaded**</font>\"</i></u><HR>";
?>

	<h1 class="title">Level Upload</h1>
	<div id="config">
	 <div id="config-content">
	   <div id="config-components">
		<div id="core-component-group1" class="component-group clearfix">
		
		<fieldset class="component-group-list">
		 <div class="field-pair">
		  <label class="nocheck clearfix" for="name_presets">
			<div align="center"><!--JUPLOAD_FILES--></div>
			<div align="center"><!--JUPLOAD_APPLET--></div>  
			<BR><BR>
			<div align="center">Select the root folder that contains your Level, do not select a zip file, files must be extracted!</div>  
		   </label>
		 </div>
		</fieldset>
		
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

<SCRIPT type="text/javascript">
function UpdateGame(selected)
{
	window.location = "../updateselectedgame.php?game="+selected;	
}
</SCRIPT>
