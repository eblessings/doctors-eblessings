<?php

namespace Friendica\Core\Console;

use Friendica\Core;
use Friendica\Core\Update;
use Friendica\Database\DBA;
use Friendica\Database\DBStructure;
use RuntimeException;

require_once 'boot.php';
require_once 'include/dba.php';

/**
 * @brief Performs database updates from the command line
 *
 * @author Hypolite Petovan <hypolite@mrpetovan.com>
 */
class DatabaseStructure extends \Asika\SimpleConsole\Console
{
	protected $helpOptions = ['h', 'help', '?'];

	protected function getHelp()
	{
		$help = <<<HELP
console dbstructure - Performs database updates
Usage
	bin/console dbstructure <command> [-h|--help|-?] |-f|--force] [-v]

Commands
	dryrun   Show database update schema queries without running them
	update   Update database schema
	dumpsql  Dump database schema
	toinnodb Convert all tables from MyISAM to InnoDB

Options
    -h|--help|-? Show help information
    -v           Show more debug information.
    -f|--force   Force the command in case of "update" (Ignore failed updates/running updates)
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

		if (count($this->args) > 1) {
			throw new \Asika\SimpleConsole\CommandArgsException('Too many arguments');
		}

		if (!DBA::connected()) {
			throw new RuntimeException('Unable to connect to database');
		}

		Core\Config::load();

		switch ($this->getArgument(0)) {
			case "dryrun":
				$output = DBStructure::update(true, false);
				break;
			case "update":
				$force = $this->getOption(['f', 'force'], false);
				$output = Update::run($force, true, false);
				break;
			case "dumpsql":
				ob_start();
				DBStructure::printStructure();
				$output = ob_get_clean();
				break;
			case "toinnodb":
				ob_start();
				DBStructure::convertToInnoDB();
				$output = ob_get_clean();
				break;
		}

		$this->out($output);

		return 0;
	}

}
