<?php

use Friendica\App;

/**
 * load view/theme/$current_theme/style.php with friendica context
 *
 * @param App $a
 */
function view_init(App $a)
{
	header("Content-Type: text/css");
		
	if ($a->argc == 4){
		$theme = $a->argv[2];
		$THEMEPATH = "view/theme/$theme";
		if(file_exists("view/theme/$theme/style.php"))
			require_once("view/theme/$theme/style.php");
	}
	
	exit();
}
