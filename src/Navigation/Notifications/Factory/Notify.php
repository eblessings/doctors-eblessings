<?php

namespace Friendica\Navigation\Notifications\Factory;

use Friendica\BaseFactory;
use Friendica\Capabilities\ICanCreateFromTableRow;
use Friendica\Content\Text\BBCode;
use GuzzleHttp\Psr7\Uri;

class Notify extends BaseFactory implements ICanCreateFromTableRow
{
	public function createFromTableRow(array $row): \Friendica\Navigation\Notifications\Entity\Notify
	{
		return new \Friendica\Navigation\Notifications\Entity\Notify(
			$row['type'],
			$row['name'],
			new Uri($row['url']),
			new Uri($row['photo']),
			new \DateTime($row['date'], new \DateTimeZone('UTC')),
			$row['uid'],
			new Uri($row['link']),
			$row['seen'],
			$row['verb'],
			$row['otype'],
			$row['name_cache'],
			$row['msg'],
			$row['msg_cache'],
			$row['iid'],
			$row['uri-id'],
			$row['parent'],
			$row['parent-uri-id'],
			$row['id']
		);
	}

	public function createFromParams($params, $itemlink = null, $item_id = null, $uri_id = null, $parent_id = null, $parent_uri_id = null): \Friendica\Navigation\Notifications\Entity\Notify
	{
		return new \Friendica\Navigation\Notifications\Entity\Notify(
			$params['type'] ?? '',
			$params['source_name'] ?? '',
			new Uri($params['source_link'] ?? ''),
			new Uri($params['source_photo'] ?? ''),
			new \DateTime(),
			$params['uid'] ?? 0,
			new Uri($itemlink ?? ''),
			false,
			$params['verb'] ?? '',
			$params['otype'] ?? '',
			substr(strip_tags(BBCode::convertForUriId($uri_id, $params['source_name'])), 0, 255),
			null,
			null,
			$item_id,
			$uri_id,
			$parent_id,
			$parent_uri_id
		);
	}
}
