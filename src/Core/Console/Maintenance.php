<?php

namespace Friendica\Core\Console;

use Friendica\Core;

require_once 'boot.php';
require_once 'include/dba.php';

/**
 * @brief tool to silence accounts on the global community page
 *
 * With this tool, you can silence an account on the global community page.
 * Postings from silenced accounts will not be displayed on the community
 * page. This silencing does only affect the display on the community page,
 * accounts following the silenced accounts will still get their postings.
 *
 * Usage: pass the URL of the profile to be silenced account as only parameter
 *        at the command line when running this tool. E.g.
 *
 *        $> util/global_community_silence.php http://example.com/profile/bob
 *
 *        will silence bob@example.com so that his postings won't appear at
 *        the global community page.
 *
 * License: AGPLv3 or later, same as Friendica
 *
 * @author Tobias Diekershoff
 * @author Hypolite Petovan <mrpetovan@gmail.com>
 */
class Maintenance extends \Asika\SimpleConsole\Console
{
	protected $helpOptions = ['h', 'help', '?'];

	protected function getHelp()
	{
		$help = <<<HELP
console maintenance - Sets maintenance mode for this node
Usage
	bin/console maintenance <enable> [<reason>] [-h|--help|-?] [-v]

Description
	<enable> cen be either 0 or 1 to disabled or enable the maintenance mode on this node.

	<reason> is a quote-enclosed string with the optional reason for the maintenance mode.

Examples
	bin/console maintenance 1
		Enables the maintenance mode without setting a reason message

	bin/console maintenance 1 "SSL certification update"
		Enables the maintenance mode with setting a reason message

	bin/console maintenance 0
		Disables the maintenance mode

Options
    -h|--help|-? Show help information
    -v           Show more debug information.
HELP;
		return $help;
	}

	protected function doExecute()
	{
		if ($this->getOption('v')) {
			$this->out('Class: ' . __CLASS__);
			$this->out('Arguments: ' . var_export($this->args, true));
			$this->out('Options: ' . var_export($this->options, true));
		}

		if (count($this->args) == 0) {
			$this->out($this->getHelp());
			return 0;
		}

		if (count($this->args) > 2) {
			throw new \Asika\SimpleConsole\CommandArgsException('Too many arguments');
		}

		require_once '.htconfig.php';
		$result = \dba::connect($db_host, $db_user, $db_pass, $db_data);
		unset($db_host, $db_user, $db_pass, $db_data);

		if (!$result) {
			throw new \RuntimeException('Unable to connect to database');
		}

		Core\Config::load();

		$lang = Core\L10n::getBrowserLanguage();
		Core\L10n::loadTranslationTable($lang);

		$enabled = intval($this->getArgument(0));

		Core\Config::set('system', 'maintenance', $enabled);

		$reason = $this->getArgument(1);

		if ($enabled && $this->getArgument(1)) {
			Core\Config::set('system', 'maintenance_reason', $this->getArgument(1));
		} else {
			Core\Config::set('system', 'maintenance_reason', '');
		}

		if ($enabled) {
			$mode_str = "maintenance mode";
		} else {
			$mode_str = "normal mode";
		}

		$this->out('System set in ' . $mode_str);

		if ($enabled && $reason != '') {
			$this->out('Maintenance reason: ' . $reason);
		}

		return 0;
	}

}
