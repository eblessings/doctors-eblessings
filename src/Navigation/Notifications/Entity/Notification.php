<?php

namespace Friendica\Navigation\Notifications\Entity;

use DateTime;
use Friendica\BaseEntity;

/**
 * @property-read $id
 * @property-read $uid
 * @property-read $verb
 * @property-read $type
 * @property-read $actorId
 * @property-read $targetUriId
 * @property-read $parentUriId
 * @property-read $created
 * @property-read $seen
 */
class Notification extends BaseEntity
{
	/** @var int */
	protected $id;
	/** @var int */
	protected $uid;
	/** @var string */
	protected $verb;
	/**
	 * @var int One of the \Friendica\Model\Post\UserNotification::TYPE_* constant values
	 * @see \Friendica\Model\Post\UserNotification
	 */
	protected $type;
	/** @var int */
	protected $actorId;
	/** @var int */
	protected $targetUriId;
	/** @var int */
	protected $parentUriId;
	/** @var DateTime */
	protected $created;
	/** @var bool */
	protected $seen;

	/**
	 * Please do not use this constructor directly, instead use one of the method of the Notification factory.
	 *
	 * @param int           $uid
	 * @param string        $verb
	 * @param int           $type
	 * @param int           $actorId
	 * @param int|null      $targetUriId
	 * @param int|null      $parentUriId
	 * @param DateTime|null $created
	 * @param bool          $seen
	 * @param int|null      $id
	 * @see \Friendica\Navigation\Notifications\Factory\Notification
	 */
	public function __construct(int $uid, string $verb, int $type, int $actorId, int $targetUriId = null, int $parentUriId = null, DateTime $created = null, bool $seen = false, int $id = null)
	{
		$this->uid         = $uid;
		$this->verb        = $verb;
		$this->type        = $type;
		$this->actorId     = $actorId;
		$this->targetUriId = $targetUriId;
		$this->parentUriId = $parentUriId ?: $targetUriId;
		$this->created     = $created;
		$this->seen        = $seen;
		$this->id          = $id;
	}

	public function setSeen()
	{
		$this->seen = true;
	}
}
