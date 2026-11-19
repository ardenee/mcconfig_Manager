<?php
$CONFIG_INI = parse_ini_file("settings.ini", true, INI_SCANNER_RAW);
$parts  = explode("|", $CONFIG_INI["Config"]["apps"]);
$game   = $CONFIG_INI['Config']['selected'];
$path   = $CONFIG_INI[$game]['folder_path'];
$folder = $CONFIG_INI[$game]['path'];

if(isset($_GET['folder']))
	$folder = $_GET['folder']."/";
	
$foundFiles = array();

//echo "<pre>";
foreach (new DirectoryIterator($folder) as $fn) 
{
	 $file = $fn->getPathname();
	 
	 if(is_file($file))	
	{	 
		$path_parts = pathinfo($file);
		
		if(strtolower($path_parts['extension'])=="jar" and $file!=$_GET['curjarfile']) // dont add current jar
			$foundFiles[] = $file;
	}
 } 
 
 //List found Files
 
 //print_r($foundFiles);
 //echo "</pre>";
 
 if(count($foundFiles)!=1)
 {

	echo "<HTML>\n";
	echo " <HEAD>\n";
	echo "  <TITLE>Minecraft Config</TITLE>\n";
	echo "  <META CHARSET=\"utf-8\" />\n";
	echo "  <META content=\"IE=edge,chrome=1\" http-equiv=\"X-UA-Compatible\" />\n";
	echo "  <META name=\"robots\" content=\"noindex\" />\n";
	echo "  <META name=\"GENERATOR\" content=\"MSHTML 8.00.7601.17940\" />\n";
	echo "  <!--[if lt IE 9]>\n";
	echo "    <SCRIPT SRC=\"files/html5.js\"></SCRIPT>\n";
	echo "  <![endif]--> \n";
	echo "  <LINK REL=\"shortcut icon\" HREF=\"images/mcconfig.png\" />\n";
	echo "  <LINK REL=\"stylesheet\" TYPE=\"text/css\" HREF=\"files/bootstrap.css\" /> \n";
	echo "  <LINK REL=\"stylesheet\" TYPE=\"text/css\" HREF=\"files/jquery.pnotify.default.css\" /> \n"; 
	echo "  <LINK REL=\"stylesheet\" TYPE=\"text/css\" HREF=\"files/jquery-ui-1.8.24.custom.css\" />\n";
	echo "  <LINK REL=\"stylesheet\" TYPE=\"text/css\" HREF=\"files/jquery.qtip.css\" />\n";
	echo "  <LINK REL=\"stylesheet\" TYPE=\"text/css\" HREF=\"files/style.css\" /> \n";
	echo "  <LINK REL=\"stylesheet\" TYPE=\"text/css\" HREF=\"files/imgs.css\" /> \n";
	  
	echo "  <SCRIPT TYPE=\"text/javascript\" SRC=\"files/jquery-1.7.2.min.js\"></SCRIPT>\n";
	echo "  <SCRIPT TYPE=\"text/javascript\" SRC=\"files/jquery-ui-1.8.24.custom.min.js\"></SCRIPT>\n";
	echo "  <SCRIPT TYPE=\"text/javascript\" SRC=\"files/bootstrap.min.js\"></SCRIPT>\n";
	echo "  <SCRIPT TYPE=\"text/javascript\" SRC=\"files/jquery.cookie.js\"></SCRIPT>\n";
	echo "  <SCRIPT TYPE=\"text/javascript\" SRC=\"files/jquery.cookiejar.js\"></SCRIPT>\n";
	echo "  <SCRIPT TYPE=\"text/javascript\" SRC=\"files/jquery.json-2.2.min.js\"></SCRIPT>\n";
	echo "  <SCRIPT TYPE=\"text/javascript\" SRC=\"files/jquery.selectboxes.min.js\"></SCRIPT>\n";
	echo "  <SCRIPT TYPE=\"text/javascript\" SRC=\"files/jquery.tablesorter-2.1.19.min.js\"></SCRIPT>\n";
	echo "  <SCRIPT TYPE=\"text/javascript\" SRC=\"files/jquery.tablesorter.widgets.min.js\"></SCRIPT>\n";
	echo "  <SCRIPT TYPE=\"text/javascript\" SRC=\"files/jquery.qtip-2012-04-26.min.js\"></SCRIPT>\n";
	echo "  <SCRIPT TYPE=\"text/javascript\" SRC=\"files/jquery.pnotify-1.2.0.min.js\"></SCRIPT>\n";
	echo "  <SCRIPT TYPE=\"text/javascript\" SRC=\"files/jquery.expand-1.3.8.js\"></SCRIPT>  \n";
	echo "  <SCRIPT TYPE=\"text/javascript\" SRC=\"files/jquery.form-2.92.js\"></SCRIPT>  \n";
	echo "  <SCRIPT TYPE=\"text/javascript\" CHARSET=\"utf-8\">\n";
	echo "	    sbRoot = '/'; \n";
	echo "      top_image_html = '<img SRC=\"images/top.gif\" style=\"width:31px; height:11px\" alt=\"Jump to top\" />'; \n";
	echo "  </SCRIPT>  \n";
	echo "  <SCRIPT TYPE=\"text/javascript\" SRC=\"files/jquery.scrolltopcontrol-1.1.js\"></SCRIPT>\n";
	echo "  <SCRIPT TYPE=\"text/javascript\" SRC=\"files/ajaxNotifications.js\"></SCRIPT>\n";
	echo "  <SCRIPT TYPE=\"text/javascript\" SRC=\"files/script.js\"></SCRIPT>\n";
	
	echo "  <STYLE TYPE=\"text/css\">\n";
	echo "   table.one {border-collapse:collapse;}\n";
	echo "   td.a {\n";
	echo "   	  border-style:solid;\n"; 
	echo "   	  border-width:1px;\n"; 
	echo "   	  border-color:#000000;\n";
	echo "   	  padding: 2px;\n";
	echo "   }\n";
	echo "  </STYLE>\n";	
	echo " </HEAD>\n";
	
	echo " <HEADER>\n";
	echo "  <DIV id=SubMenu><SPAN>\n";
	echo "  <A HREF=\"index.php\">Home</A> | \n";
	echo "  <A HREF=\"log.php\">Logs</A> | \n";
	echo "  <A HREF=\"ops.php\">Ops</A> | \n";
	echo "  <A HREF=\"ban.php\">Ban</A> | \n";
	echo "  <A HREF=\"whitelist.php\">Whitelist</A> | \n";
	echo "  <A HREF=\"jarupgrade.php\">jar Upgrade</A> | \n";
	echo "  <A HREF=\"settings.php\">Settings</A> | \n";
	echo "  <A target=\"_Blank\" HREF=\"http://minecraft.gamepedia.com/Server.properties\">Help</A> | \n";
	echo "  <A HREF=\"about.php\">About</A> | \n";
 
	echo "     <select name=\"game\" id=\"game\" class=\"input-medium\" OnChange=\"UpdateGame(this.options[this.selectedIndex].value);\">\n";

		foreach($parts as $key => $value)
			echo "        <option value=\"$value\"".(($game==$value)?" SELECTED":"").">$value</option>\n";

 	echo "    </select> |\n";
	echo " 	<A HREF=\"feedback.php\">Feedback</A> \n";
	echo "  </SPAN></DIV>\n";
	echo " </HEADER>\n";
	
	echo " <BODY>\n";
	echo "	<div id=\"contentWrapper\">\n";
	echo "	<div id=\"content\">\n";
	echo "<h1 class=\"title\">jar file upgrade</h1>\n";

	echo "<div id=\"config\">\n";
	echo " <div id=\"config-content\">\n";
	echo "  <form name=\"jarupgrade\" id=\"jarupgrade\" action=\"upgrade.php\" method=\"post\">\n";
	echo "   <div id=\"config-components\">\n";
	echo "    <div id=\"core-component-group1\" class=\"component-group clearfix\">\n";
	echo "    <div class=\"component-group-desc\">\n";
	echo " 	 <h3>Upgrade</h3>\n";
	echo " 	 <p>Select jar</p>\n";
	echo " 	</div> <!--component-group-desc-->\n";		
		
	echo "<fieldset class=\"component-group-list\">\n";
	echo " <div class=\"field-pair\">\n";

	foreach($foundFiles as $key => $val)
	{
		$path_parts = pathinfo(trim($val));
		$file       = $path_parts['basename'];
		
		
		echo "  <label class=\"nocheck clearfix\">\n";
		echo "   <input type=\"radio\" id=\"dljarfile\" name=\"dljarfile\" value=\"$val\" ".(($key==0)?"checked":"")."><span title=\"Size: ".number_format(filesize($val))." bytes\">".$path_parts['dirname']."/<b>".$path_parts['basename']."</b></span><BR>\n";
		echo "  </label>\n";
	}
	
	echo "<input type=\"hidden\" name=\"curjarfile\" value=\"".$_GET['curjarfile']."\">\n";

	echo "   <label class=\"nocheck clearfix\">\n";
	echo "	  <span class=\"component-title\">&nbsp;</span>\n";
	echo "	  <span class=\"component-desc\" id=\"op-permission-level-comment\"><BR>Select from found jar files.</span>\n";
	echo "   </label>\n";

	echo " </div>\n";
	echo "</fieldset>\n";
		
	echo "<fieldset class=\"component-group-list\">\n";
	echo " <div class=\"component-group-save\">\n";
	 

	echo "<input type=\"submit\" class=\"btn config_submitter\" value=\"Select\"/>\n";

	 echo "</div>\n";

	echo "</div><!-- /component-group1 //-->\n";
	 echo "</div><!-- /config-components -->\n";
	echo " </form>\n";
	echo " </div>\n";
	echo "</div>\n";
	echo "   </DIV><!-- /content -->\n";
	echo "  </DIV><!-- /contentWrapper -->\n";
	echo "   <FOOTER>\n";
	echo "   <DIV class=\"container footer\"><B>mcconfig Scan</b></DIV><!-- /container footer -->\n";
	echo "   </FOOTER>\n";
	echo " </BODY>\n";
	echo "</HTML>\n"; 
	
	echo "<SCRIPT type=\"text/javascript\">\n";
	echo "function UpdateGame(selected)\n";
	echo "{\n";
	echo "    window.location = \"updateselectedgame.php?game=\"+selected;\n";	
	echo "}\n";
	echo "</SCRIPT>\n";
}
else 
{
	$curdate    = new DateTime();
	$curdatestr = $curdate->format('Y-m-d_H-i-s');	
	$path_parts = pathinfo($_GET['curjarfile']);	
	$newname    = $path_parts['dirname']."/".$path_parts['filename']."-".$curdatestr.".".$path_parts['extension'];	
	rename($_GET['curjarfile'], $newname);// backup old jar file	
	copy($foundFiles[0], $_GET['curjarfile']);	
	
	header("location: jarupgrade.php");	
}
 ?>