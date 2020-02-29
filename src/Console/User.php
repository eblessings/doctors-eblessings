<?php
/**
 * @copyright Copyright (C) 2020, Friendica
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

namespace Friendica\Console;

use Friendica\App;
use Friendica\Core\L10n;
use Friendica\Database\Database;
use Friendica\Model\Register;
use Friendica\Model\User as UserModel;
use RuntimeException;
use Seld\CliPrompt\CliPrompt;

/**
 * tool to set a new password for a user
 *
 * With this tool, you can set a new password for a user
 */
class User extends \Asika\SimpleConsole\Console
{
	protected $helpOptions = ['h', 'help', '?'];

	/**
	 * @var App\Mode
	 */
	private $appMode;
	/**
	 * @var L10n
	 */
	private $l10n;
	/**
	 * @var Database
	 */
	private $dba;

	protected function getHelp()
	{
		$help = <<<HELP
console user - Modify user settings per console commands.
Usage
	bin/console user password <nickname> [<password>] [-h|--help|-?] [-v]
	bin/console user add [<name> [<nickname> [<email> [<language>]]]] [-h|--help|-?] [-v]
	bin/console user allow [<nickname>] [-h|--help|-?] [-v]

Description
	Modify user settings per console commands.

Options
    -h|--help|-? Show help information
    -v           Show more debug information.
HELP;
		return $help;
	}

	public function __construct(App\Mode $appMode, L10n $l10n, Database $dba, array $argv = null)
	{
		parent::__construct($argv);

		$this->appMode     = $appMode;
		$this->l10n        = $l10n;
		$this->dba         = $dba;
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

		if ($this->appMode->isInstall()) {
			throw new RuntimeException('Database isn\'t ready or populated yet');
		}

		$command = $this->getArgument(0);

		switch ($command) {
			case 'password':
				return $this->password();
			case 'add':
				return $this->addUser();
			case 'allow':
				return $this->allowUser();
			default:
				throw new \Asika\SimpleConsole\CommandArgsException('Wrong command.');
		}
	}

	/**
	 * Sets a new password
	 *
	 * @return int Return code of this command
	 *
	 * @throws \Exception
	 */
	private function password()
	{
		$nick = $this->getArgument(1);

		$user = $this->dba->selectFirst('user', ['uid'], ['nickname' => $nick]);
		if (!$this->dba->isResult($user)) {
			throw new RuntimeException($this->l10n->t('User not found'));
		}

		$password = $this->getArgument(2);

		if (is_null($password)) {
			$this->out($this->l10n->t('Enter new password: '), false);
			$password = CliPrompt::hiddenPrompt(true);
		}

		try {
			$result = UserModel::updatePassword($user['uid'], $password);

			if (!$this->dba->isResult($result)) {
				throw new \Exception($this->l10n->t('Password update failed. Please try again.'));
			}

			$this->out($this->l10n->t('Password changed.'));
		} catch (\Exception $e) {
			throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
		}

		return 0;
	}

	/**
	 * Adds a new user based on given console arguments
	 *
	 * @return bool True, if the command was successful
	 * @throws \ErrorException
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	private function addUser()
	{
		$name  = $this->getArgument(1);
		$nick  = $this->getArgument(2);
		$email = $this->getArgument(3);
		$lang  = $this->getArgument(4);

		if (empty($name)) {
			$this->out($this->l10n->t('Enter user name: '));
			$name = CliPrompt::prompt();
			if (empty($name)) {
				throw new RuntimeException('A name must be set.');
			}
		}

		if (empty($nick)) {
			$this->out($this->l10n->t('Enter user nickname: '));
			$nick = CliPrompt::prompt();
			if (empty($nick)) {
				throw new RuntimeException('A nick name must be set.');
			}
		}

		if (empty($email)) {
			$this->out($this->l10n->t('Enter user email address: '));
			$email = CliPrompt::prompt();
			if (empty($email)) {
				throw new RuntimeException('A email address must be set.');
			}
		}

		if (empty($lang)) {
			$this->out($this->l10n->t('Enter a language (optional): '));
			$lang = CliPrompt::prompt();
		}

		if (empty($lang)) {
			return UserModel::createMinimal($name, $email, $nick);
		} else {
			return UserModel::createMinimal($name, $email, $nick, $lang);
		}
	}

	/**
	 * Allows a user based on it's nickname
	 *
	 * @return bool True, if allow was successful
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public function allowUser()
	{
		$nick = $this->getArgument(1);

		if (!$nick) {
			$this->out($this->l10n->t('Enter user nickname: '));
			$nick = CliPrompt::prompt();
			if (empty($nick)) {
				throw new RuntimeException('A nick name must be set.');
			}
		}

		$user = $this->dba->selectFirst('user', ['uid'], ['nickname' => $nick]);
		if (empty($user)) {
			throw new RuntimeException($this->l10n->t('User not found'));
		}

		$pending = Register::getPendingForUser($user['uid'] ?? 0);
		if (empty($pending)) {
			throw new RuntimeException($this->l10n->t('User is not pending.'));
		}

		return UserModel::allow($pending['hash']);
	}
}
