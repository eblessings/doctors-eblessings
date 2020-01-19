<?php
/**
 * @file view/theme/duepuntozero/style.php
 */
use Friendica\Core\Config;
use Friendica\DI;

if (file_exists("$THEMEPATH/style.css")) {
	echo file_get_contents("$THEMEPATH/style.css");
}

$uid = $_REQUEST['puid'] ?? 0;

$s_colorset = DI::config()->get('duepuntozero', 'colorset');
$colorset = DI::pConfig()->get($uid, 'duepuntozero', 'colorset');

if (empty($colorset)) {
	$colorset = $s_colorset;
}

$setcss = '';

if ($colorset) {
	if ($colorset == 'greenzero') {
		$setcss = file_get_contents('view/theme/duepuntozero/deriv/greenzero.css');
	}

	if ($colorset == 'purplezero') {
		$setcss = file_get_contents('view/theme/duepuntozero/deriv/purplezero.css');
	}

	if ($colorset == 'easterbunny') {
		$setcss = file_get_contents('view/theme/duepuntozero/deriv/easterbunny.css');
	}

	if ($colorset == 'darkzero') {
		$setcss = file_get_contents('view/theme/duepuntozero/deriv/darkzero.css');
	}

	if ($colorset == 'comix') {
		$setcss = file_get_contents('view/theme/duepuntozero/deriv/comix.css');
	}

	if ($colorset == 'slackr') {
		$setcss = file_get_contents('view/theme/duepuntozero/deriv/slackr.css');
	}
}

echo $setcss;
