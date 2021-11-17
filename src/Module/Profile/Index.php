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

namespace Friendica\Module\Profile;

use Friendica\BaseModule;
use Friendica\Core\L10n;

/**
 * Profile index router
 *
 * The default profile path (https://domain.tld/profile/nickname) has to serve the profile data when queried as an
 * ActivityPub endpoint, but it should show statuses to web users.
 *
 * Both these view have dedicated sub-paths,
 * respectively https://domain.tld/profile/nickname/profile and https://domain.tld/profile/nickname/status
 */
class Index extends BaseModule
{
	public function __construct(L10n $l10n, array $parameters = [])
	{
		parent::__construct($l10n, $parameters);
	}

	public function rawContent()
	{
		(new Profile($this->l10n, $this->parameters))->rawContent();
	}

	public function content(): string
	{
		return (new Status($this->l10n, $this->parameters))->content();
	}
}
