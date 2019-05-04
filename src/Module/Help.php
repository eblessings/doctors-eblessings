<?php

namespace Friendica\Module;

use Friendica\BaseModule;
use Friendica\Content\Nav;
use Friendica\Content\Text\Markdown;
use Friendica\Core\L10n;
use Friendica\Network\HTTPException;
use Friendica\Util\Strings;

/**
 * Shows the friendica help based on the /doc/ directory
 */
class Help extends BaseModule
{
	public static function content()
	{
		Nav::setSelected('help');

		$text = '';
		$filename = '';

		$app = self::getApp();
		$config = $app->getConfig();
		$lang = $config->get('system', 'language');

		// @TODO: Replace with parameter from router
		if ($app->argc > 1) {
			$path = '';
			// looping through the argv keys bigger than 0 to build
			// a path relative to /help
			for ($x = 1; $x < $app->argc; $x ++) {
				if (strlen($path)) {
					$path .= '/';
				}

				$path .= $app->getArgumentValue($x);
			}
			$title = basename($path);
			$filename = $path;
			$text = self::loadDocFile('doc/' . $path . '.md', $lang);
			$app->page['title'] = L10n::t('Help:') . ' ' . str_replace('-', ' ', Strings::escapeTags($title));
		}

		$home = self::loadDocFile('doc/Home.md', $lang);
		if (!$text) {
			$text = $home;
			$filename = "Home";
			$app->page['title'] = L10n::t('Help');
		} else {
			$app->page['aside'] = Markdown::convert($home, false);
		}

		if (!strlen($text)) {
			throw new HTTPException\NotFoundException();
		}

		$html = Markdown::convert($text, false);

		if ($filename !== "Home") {
			// create TOC but not for home
			$lines = explode("\n", $html);
			$toc = "<h2>TOC</h2><ul id='toc'>";
			$lastLevel = 1;
			$idNum = [0, 0, 0, 0, 0, 0, 0];
			foreach ($lines as &$line) {
				if (substr($line, 0, 2) == "<h") {
					$level = substr($line, 2, 1);
					if ($level != "r") {
						$level = intval($level);
						if ($level < $lastLevel) {
							for ($k = $level; $k < $lastLevel; $k++) {
								$toc .= "</ul></li>";
							}

							for ($k = $level + 1; $k < count($idNum); $k++) {
								$idNum[$k] = 0;
							}
						}

						if ($level > $lastLevel) {
							$toc .= "<li><ul>";
						}

						$idNum[$level] ++;
						$id = implode("_", array_slice($idNum, 1, $level));
						$href = $app->getBaseURL() . "/help/{$filename}#{$id}";
						$toc .= "<li><a href='{$href}'>" . strip_tags($line) . "</a></li>";
						$line = "<a name='{$id}'></a>" . $line;
						$lastLevel = $level;
					}
				}
			}

			for ($k = 0; $k < $lastLevel; $k++) {
				$toc .= "</ul>";
			}

			$html = implode("\n", $lines);

			$a->page['aside'] = '<div class="help-aside-wrapper widget"><div id="toc-wrapper">' . $toc . '</div>' . $a->page['aside'] . '</div>';
		}

		return $html;
	}

	private static function loadDocFile($fileName, $lang = 'en')
	{
		$baseName = basename($fileName);
		$dirName = dirname($fileName);
		if (file_exists("$dirName/$lang/$baseName")) {
			return file_get_contents("$dirName/$lang/$baseName");
		}

		if (file_exists($fileName)) {
			return file_get_contents($fileName);
		}

		return '';
	}
}
