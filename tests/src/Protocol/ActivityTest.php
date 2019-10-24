<?php

namespace Friendica\Test\Protocol;

use Friendica\Protocol\Activity;
use Friendica\Test\MockedTest;

class ActivityTest extends MockedTest
{
	public function dataMatch()
	{
		return [
			'empty' => [
				'haystack' => '',
				'needle' => '',
				'assert' => true,
			],
			'simple' => [
				'haystack' => Activity::OBJ_TAGTERM,
				'needle' => Activity::OBJ_TAGTERM,
				'assert' => true,
			],
			'withNamespace' => [
				'haystack' => 'tagterm',
				'needle' => Activity\ANamespace::ACTIVITY_SCHEMA . Activity::OBJ_TAGTERM,
				'assert' => true,
			],
			'invalidSimple' => [
				'haystack' => 'tagterm',
				'needle' => '',
				'assert' => false,
			],
			'invalidWithOutNamespace' => [
				'haystack' => 'tagterm',
				'needle' => Activity::OBJ_TAGTERM,
				'assert' => false,
			],
			'withSubPath' => [
				'haystack' => 'tagterm',
				'needle' => Activity\ANamespace::ACTIVITY_SCHEMA . '/bla/' . Activity::OBJ_TAGTERM,
				'assert' => true,
			],
		];
	}

	/**
	 * Test the different, possible matchings
	 *
	 * @dataProvider dataMatch
	 */
	public function testMatch(string $haystack, string $needle, bool $assert)
	{
		$activity = new Activity();

		$this->assertEquals($assert, $activity->match($haystack, $needle));
	}

	public function testIsHidden()
	{
		$activity = new Activity();

		$this->assertTrue($activity->isHidden(Activity::LIKE));
		$this->assertFalse($activity->isHidden(Activity::OBJ_BOOKMARK));
	}
}
