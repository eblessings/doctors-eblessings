<?php

/**
 * @file include/smilies.php
 */

/**
 * This class contains functions to handle smiles
 */

class smilies {

	/**
	 * @brief Function to list all smilies
	 * 
	 * Get an array of all smilies, both internal and from addons.
	 * 
	 * @return array
	 *	'texts' => smilie shortcut
	 *	'icons' => icon in html
	 * 
	 * @hook smilie ('texts' => smilies texts array, 'icons' => smilies html array)
	 */
	public static function list_smilies() {

		$texts =  array(
			'&lt;3',
			'&lt;/3',
			'&lt;\\3',
			':-)',
			';-)',
			':-(',
			':-P',
			':-p',
			':-"',
			':-&quot;',
			':-x',
			':-X',
			':-D',
			'8-|',
			'8-O',
			':-O',
			'\\o/',
			'o.O',
			'O.o',
			'o_O',
			'O_o',
			":'(",
			":-!",
			":-/",
			":-[",
			"8-)",
			':beer',
			':homebrew',
			':coffee',
			':facepalm',
			':like',
			':dislike',
			'~friendica',
			'red#',
			'red#matrix'

		);

		$icons = array(
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-heart.gif" alt="&lt;3" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-brokenheart.gif" alt="&lt;/3" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-brokenheart.gif" alt="&lt;\\3" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-smile.gif" alt=":-)" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-wink.gif" alt=";-)" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-frown.gif" alt=":-(" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-tongue-out.gif" alt=":-P" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-tongue-out.gif" alt=":-p" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-kiss.gif" alt=":-\"" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-kiss.gif" alt=":-\"" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-kiss.gif" alt=":-x" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-kiss.gif" alt=":-X" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-laughing.gif" alt=":-D" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-surprised.gif" alt="8-|" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-surprised.gif" alt="8-O" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-surprised.gif" alt=":-O" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-thumbsup.gif" alt="\\o/" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-Oo.gif" alt="o.O" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-Oo.gif" alt="O.o" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-Oo.gif" alt="o_O" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-Oo.gif" alt="O_o" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-cry.gif" alt=":\'(" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-foot-in-mouth.gif" alt=":-!" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-undecided.gif" alt=":-/" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-embarassed.gif" alt=":-[" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-cool.gif" alt="8-)" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/beer_mug.gif" alt=":beer" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/beer_mug.gif" alt=":homebrew" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/coffee.gif" alt=":coffee" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-facepalm.gif" alt=":facepalm" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/like.gif" alt=":like" />',
			'<img class="smiley" src="' . app::get_baseurl() . '/images/dislike.gif" alt=":dislike" />',
			'<a href="http://friendica.com">~friendica <img class="smiley" src="' . app::get_baseurl() . '/images/friendica-16.png" alt="~friendica" /></a>',
			'<a href="http://redmatrix.me/">red<img class="smiley" src="' . app::get_baseurl() . '/images/rm-16.png" alt="red" />matrix</a>',
			'<a href="http://redmatrix.me/">red<img class="smiley" src="' . app::get_baseurl() . '/images/rm-16.png" alt="red" />matrix</a>'
		);

		$params = array('texts' => $texts, 'icons' => $icons);
		call_hooks('smilie', $params);

		return $params;

	}

	/**
	 * @brief Replaces text emoticons with graphical images
	 *
	 * It is expected that this function will be called using HTML text.
	 * We will escape text between HTML pre and code blocks from being
	 * processed.
	 *
	 * At a higher level, the bbcode [nosmile] tag can be used to prevent this
	 * function from being executed by the prepare_text() routine when preparing
	 * bbcode source for HTML display
	 *
	 * @param string $s
	 * @param boolean $sample
	 * 
	 * @return string
	 */
	public static function replace($s, $sample = false) {
		if(intval(get_config('system','no_smilies'))
			|| (local_user() && intval(get_pconfig(local_user(),'system','no_smilies'))))
			return $s;

		$s = preg_replace_callback('/<pre>(.*?)<\/pre>/ism','self::smile_encode',$s);
		$s = preg_replace_callback('/<code>(.*?)<\/code>/ism','self::smile_encode',$s);

		$params = self::list_smilies();
		$params['string'] = $s;

		if($sample) {
			$s = '<div class="smiley-sample">';
			for($x = 0; $x < count($params['texts']); $x ++) {
				$s .= '<dl><dt>' . $params['texts'][$x] . '</dt><dd>' . $params['icons'][$x] . '</dd></dl>';
			}
		}
		else {
			$params['string'] = preg_replace_callback('/&lt;(3+)/','self::preg_heart',$params['string']);
			$s = str_replace($params['texts'],$params['icons'],$params['string']);
		}

		$s = preg_replace_callback('/<pre>(.*?)<\/pre>/ism','self::smile_decode',$s);
		$s = preg_replace_callback('/<code>(.*?)<\/code>/ism','self::smile_decode',$s);

		return $s;
	}

	private function smile_encode($m) {
		return(str_replace($m[1],base64url_encode($m[1]),$m[0]));
	}

	private function smile_decode($m) {
		return(str_replace($m[1],base64url_decode($m[1]),$m[0]));
	}


	/**
	 * @brief expand <3333 to the correct number of hearts
	 *
	 * @param string $x
	 * @return string
	 */
	private function preg_heart($x) {
		if(strlen($x[1]) == 1)
			return $x[0];
		$t = '';
		for($cnt = 0; $cnt < strlen($x[1]); $cnt ++)
			$t .= '<img class="smiley" src="' . app::get_baseurl() . '/images/smiley-heart.gif" alt="&lt;3" />';
		$r =  str_replace($x[0],$t,$x[0]);
		return $r;
	}

}
