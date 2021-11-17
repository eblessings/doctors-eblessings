<?php
/**
 * @copyright Copyright (C) 2010-2021, the Friendica project
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

namespace Friendica\Module\Filer;

use Friendica\App\BaseURL;
use Friendica\BaseModule;
use Friendica\Core\L10n;
use Friendica\Core\Renderer;
use Friendica\Database\DBA;
use Friendica\Model;
use Friendica\Network\HTTPException;
use Friendica\Util\XML;
use Psr\Log\LoggerInterface;

/**
 * Shows a dialog for adding tags to a file
 */
class SaveTag extends BaseModule
{
	/** @var LoggerInterface */
	protected $logger;
	
	public function __construct(LoggerInterface $logger, BaseURL $baseUrl, L10n $l10n, array $parameters = [])
	{
		parent::__construct($l10n, $parameters);
		
		$this->logger = $logger;
		
		if (!local_user()) {
			notice($this->l10n->t('You must be logged in to use this module'));
			$baseUrl->redirect();
		}
	}

	public function rawContent()
	{
		$term = XML::unescape(trim($_GET['term'] ?? ''));

		$item_id = $this->parameters['id'] ?? 0;

		$this->logger->info('filer', ['tag' => $term, 'item' => $item_id]);

		if ($item_id && strlen($term)) {
			$item = Model\Post::selectFirst(['uri-id'], ['id' => $item_id]);
			if (!DBA::isResult($item)) {
				throw new HTTPException\NotFoundException();
			}
			Model\Post\Category::storeFileByURIId($item['uri-id'], local_user(), Model\Post\Category::FILE, $term);
		}

		// return filer dialog
		$filetags = Model\Post\Category::getArray(local_user(), Model\Post\Category::FILE);

		$tpl = Renderer::getMarkupTemplate("filer_dialog.tpl");
		echo Renderer::replaceMacros($tpl, [
			'$field' => ['term', $this->l10n->t("Save to Folder:"), '', '', $filetags, $this->l10n->t('- select -')],
			'$submit' => $this->l10n->t('Save'),
		]);

		exit;
	}
}
