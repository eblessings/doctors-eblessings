<?php

namespace Friendica\Core\Console;

use Friendica\Core\L10n;
use Friendica\Core\Config;

/**
 * @brief tool to block an account from the node
 *
 * With this tool, you can block an account in such a way, that no postings
 * or comments this account writes are accepted to the node.
 *
 * License: AGPLv3 or later, same as Friendica
 *
 * @author Tobias Diekershoff <mrpetovan@gmail.com>
 * @author Hypolite Petovan <mrpetovan@gmail.com>
 */
class PostUpdate extends \Asika\SimpleConsole\Console
{
        protected $helpOptions = ['h', 'help', '?'];

        protected function getHelp()
        {
                $help = <<<HELP
console postupdate - Does database post updates
Usage
        bin/console postupdate [-h|--help|-?] [--reset <version>]

Options
    -h|--help|-?      Show help information
    --reset <version> Reset the post update version
HELP;
                return $help;
        }

	protected function doExecute()
	{
		$a = get_app();

		if ($this->getOption($this->helpOptions)) {
			$this->out($this->getHelp());
			return 0;
		}

		$reset_version = $this->getOption('reset');
		if (is_bool($reset_version)) {
			$this->out($this->getHelp());
			return 0;
		} elseif ($reset_version) {
			Config::set('system', 'post_update_version', $reset_version);
			echo L10n::t('Post update version number has been set to %s.', $reset_version) . "\n";
			return 0;
		}

		if ($a->isInstallMode()) {
			throw new \RuntimeException('Database isn\'t ready or populated yet');
		}

		echo L10n::t('Execute pending post updates.') . "\n";

		while (!\Friendica\Database\PostUpdate::update()) {
			echo '.';
		}

		echo "\n" . L10n::t('All pending post updates are done.') . "\n";

		return 0;
	}
}
