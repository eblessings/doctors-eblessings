<?php

use Friendica\App;
use Friendica\Core\Renderer;
use Friendica\Core\System;

function opensearch_content(App $a) {

	$tpl = get_markup_template('opensearch.tpl');

	header("Content-type: application/opensearchdescription+xml");

	$o = Renderer::replaceMacros($tpl, [
		'$baseurl' => System::baseUrl(),
		'$nodename' => $a->getHostName(),
	]);

	echo $o;

	killme();
}
