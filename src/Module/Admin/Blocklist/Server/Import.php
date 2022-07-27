<?php
/**
 * @copyright Copyright (C) 2010-2022, the Friendica project
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

namespace Friendica\Module\Admin\Blocklist\Server;

use Friendica\App;
use Friendica\Core\Config\Capability\IManageConfigValues;
use Friendica\Core\L10n;
use Friendica\Core\Renderer;
use Friendica\Module\Response;
use Friendica\Navigation\SystemMessages;
use Friendica\Util\Profiler;
use Psr\Log\LoggerInterface;

class Import extends \Friendica\Module\BaseAdmin
{
	/** @var IManageConfigValues */
	private $config;

	/** @var SystemMessages */
	private $sysmsg;

	/** @var array of blocked server domain patterns */
	private $blocklist = [];

	public function __construct(IManageConfigValues $config, SystemMessages $sysmsg, L10n $l10n, App\BaseURL $baseUrl, App\Arguments $args, LoggerInterface $logger, Profiler $profiler, Response $response, array $server, array $parameters = [])
	{
		parent::__construct($l10n, $baseUrl, $args, $logger, $profiler, $response, $server, $parameters);

		$this->config = $config;
		$this->sysmsg = $sysmsg;
	}

	/**
	 * @param array $request
	 * @return void
	 * @throws \Friendica\Network\HTTPException\ForbiddenException
	 * @throws \Friendica\Network\HTTPException\FoundException
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \Friendica\Network\HTTPException\MovedPermanentlyException
	 * @throws \Friendica\Network\HTTPException\TemporaryRedirectException
	 */
	protected function post(array $request = [])
	{
		self::checkAdminAccess();

		if (!isset($_POST['page_blocklist_upload']) && !isset($_POST['page_blocklist_import'])) {
			return;
		}

		self::checkFormSecurityTokenRedirectOnError('/admin/blocklist/server/import', 'admin_blocklist_import');

		if (isset($_POST['page_blocklist_upload'])) {
			if (($fp = fopen($_FILES['listfile']['tmp_name'], 'r')) !== false) {
				$blocklist = [];
				while (($data = fgetcsv($fp, 1000, ',')) !== false) {
					$domain = $data[0];
					if (count($data) == 0) {
						$reason = 'blocked';
					} else {
						$reason = $data[1];
					}

					$blocklist[] = [
						'domain' => $domain,
						'reason' => $reason
					];
				}
			} else {
				$this->sysmsg->addNotice($this->l10n->t('Error importing pattern file'));
				return;
			}

			$this->blocklist = $blocklist;

			return;
		}

		if (isset($_POST['page_blocklist_import'])) {
			$blocklist = json_decode($_POST['blocklist'], true);
			if ($blocklist === null) {
				$this->sysmsg->addNotice($this->l10n->t('Error importing pattern file'));
				return;
			}

			if (($_POST['mode'] ?? 'append') == 'replace') {
				$this->config->set('system', 'blocklist', $blocklist);
				$this->sysmsg->addNotice($this->l10n->t('Local blocklist replaced with the provided file.'));
			} else {
				$localBlocklist = $this->config->get('system', 'blocklist', []);
				$localPatterns  = array_column($localBlocklist, 'domain');

				$importedPatterns = array_column($blocklist, 'domain');

				$patternsToAppend = array_diff($importedPatterns, $localPatterns);

				if (count($patternsToAppend)) {
					foreach (array_keys($patternsToAppend) as $key) {
						$localBlocklist[] = $blocklist[$key];
					}

					$this->config->set('system', 'blocklist', $localBlocklist);
					$this->sysmsg->addNotice($this->l10n->tt('%d pattern was added to the local blocklist.', '%d patterns were added to the local blocklist.', count($patternsToAppend)));
				} else {
					$this->sysmsg->addNotice($this->l10n->t('No pattern was added to the local blocklist.'));
				}
			}

			$this->baseUrl->redirect('/admin/blocklist/server');
		}
	}

	/**
	 * @param array $request
	 * @return string
	 * @throws \Friendica\Network\HTTPException\ServiceUnavailableException
	 */
	protected function content(array $request = []): string
	{
		parent::content();

		$t = Renderer::getMarkupTemplate('admin/blocklist/server/import.tpl');
		return Renderer::replaceMacros($t, [
			'$l10n' => [
				'return_list'    => $this->l10n->t('← Return to the list'),
				'title'          => $this->l10n->t('Administration'),
				'page'           => $this->l10n->t('Import a Server Domain Pattern Blocklist'),
				'download'       => $this->l10n->t('<p>This file can be downloaded from the <code>/friendica</code> path of any Friendica server.</p>'),
				'upload'         => $this->l10n->t('Upload file'),
				'patterns'       => $this->l10n->t('Patterns to import'),
				'domain_pattern' => $this->l10n->t('Domain Pattern'),
				'block_reason'   => $this->l10n->t('Block Reason'),
				'mode'           => $this->l10n->t('Import Mode'),
				'import'         => $this->l10n->t('Import Patterns'),
				'pattern_count'  => $this->l10n->tt('%d total pattern', '%d total patterns', count($this->blocklist)),
			],
			'$listfile'            => ['listfile', $this->l10n->t('Server domain pattern blocklist CSV file'), '', '', $this->l10n->t('Required'), '', 'file'],
			'$mode_append'         => ['mode', $this->l10n->t('Append'), 'append', $this->l10n->t('Imports patterns from the file that weren\'t already existing in the current blocklist.'), 'checked="checked"'],
			'$mode_replace'        => ['mode', $this->l10n->t('Replace'), 'replace', $this->l10n->t('Replaces the current blocklist by the imported patterns.')],
			'$blocklist'           => $this->blocklist,
			'$baseurl'             => $this->baseUrl->get(true),
			'$form_security_token' => self::getFormSecurityToken('admin_blocklist_import')
		]);
	}
}
