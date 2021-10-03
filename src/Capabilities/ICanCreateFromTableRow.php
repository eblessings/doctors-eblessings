<?php

namespace Friendica\Capabilities;

use Friendica\BaseEntity;

interface ICanCreateFromTableRow
{
	/**
	 * Returns the correcponding Entity given a table row record
	 *
	 * @param array $row
	 * @return BaseEntity
	 */
	public function createFromTableRow(array $row);
}
