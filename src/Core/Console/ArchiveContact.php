<?php

namespace Friendica\Core\Console;

use Friendica\App;
use Friendica\Core\L10n;
use Friendica\Database\DBA;
use RuntimeException;

/**
 * @brief tool to archive a contact on the server
 *
 * With this tool you can archive a contact when you know that it isn't existing anymore.
 * Normally this does happen automatically after a few days.
 *
 * License: AGPLv3 or later, same as Friendica
 *
 */
class ArchiveContact extends \Asika\SimpleConsole\Console
{
	protected $helpOptions = ['h', 'help', '?'];

	protected function getHelp()
	{
		$help = <<<HELP
console archivecontact - archive a contact
Usage
	bin/console archivecontact <profile_url> [-h|--help|-?] [-v]

Description
	Archive a contact when you know that it isn't existing anymore. Normally this does happen automatically after a few days.

Options
    -h|--help|-? Show help information
    -v           Show more debug information.
HELP;
		return $help;
	}

	protected function doExecute()
	{
		$a = get_app();

		if ($this->getOption('v')) {
			$this->out('Class: ' . __CLASS__);
			$this->out('Arguments: ' . var_export($this->args, true));
			$this->out('Options: ' . var_export($this->options, true));
		}

		if (count($this->args) == 0) {
			$this->out($this->getHelp());
			return 0;
		}

		if (count($this->args) > 1) {
			throw new \Asika\SimpleConsole\CommandArgsException('Too many arguments');
		}

		if ($a->mode === App::MODE_INSTALL) {
			throw new RuntimeException('Friendica isn\'t properly installed yet.');
		}

		$nurl = normalise_link($this->getArgument(0));
		if (!DBA::exists('contact', ['nurl' => $nurl, 'archive' => false])) {
			throw new RuntimeException(L10n::t('Could not find any unarchived contact entry for this URL (%s)', $nurl));
		}
		if (DBA::update('contact', ['archive' => true], ['nurl' => $nurl])) {
			$condition = ["`cid` IN (SELECT `id` FROM `contact` WHERE `archive`)"];
			DBA::delete('queue', $condition);
			$this->out(L10n::t('The contact entries have been archived'));
		} else {
			throw new RuntimeException('The contact archival failed.');
		}

		return 0;
	}
}
