<?php
/**
 * TextTest class.
 */

namespace Friendica\Test;

use PHPUnit\Framework\TestCase;

/**
 * Tests for text functions.
 */
class TextTest extends TestCase
{

	/**
	 *autonames should be random, even length
	 */
	public function testAutonameEven()
	{
		$autoname1= Friendica\Util\Strings::getRandomName(10);
		$autoname2= Friendica\Util\Strings::getRandomName(10);

		$this->assertNotEquals($autoname1, $autoname2);
	}

	/**
	 *autonames should be random, odd length
	 */
	public function testAutonameOdd()
	{
		$autoname1= Friendica\Util\Strings::getRandomName(9);
		$autoname2= Friendica\Util\Strings::getRandomName(9);

		$this->assertNotEquals($autoname1, $autoname2);
	}

	/**
	 * try to fail autonames
	 */
	public function testAutonameNoLength()
	{
		$autoname1= Friendica\Util\Strings::getRandomName(0);
		$this->assertEquals(0, strlen($autoname1));
	}

	/**
	 * try to fail it with invalid input
	 *
	 * @todo What's corect behaviour here? An exception?
	 */
	public function testAutonameNegativeLength()
	{
		$autoname1= Friendica\Util\Strings::getRandomName(-23);
		$this->assertEquals(0, strlen($autoname1));
	}

	/**
	 * test with a length, that may be too short
	 */
	public function testAutonameLength1()
	{
		$autoname1= Friendica\Util\Strings::getRandomName(1);
		$this->assertEquals(1, strlen($autoname1));

		$autoname2= Friendica\Util\Strings::getRandomName(1);
		$this->assertEquals(1, strlen($autoname2));
	}

	/**
	 * test attribute contains
	 */
	public function testAttributeContains1()
	{
		$testAttr="class1 notclass2 class3";
		$this->assertTrue(attribute_contains($testAttr, "class3"));
		$this->assertFalse(attribute_contains($testAttr, "class2"));
	}

	/**
	 * test attribute contains
	 */
	public function testAttributeContains2()
	{
		$testAttr="class1 not-class2 class3";
		$this->assertTrue(attribute_contains($testAttr, "class3"));
		$this->assertFalse(attribute_contains($testAttr, "class2"));
	}

	/**
	 * test with empty input
	 */
	public function testAttributeContainsEmpty()
	{
		$testAttr="";
		$this->assertFalse(attribute_contains($testAttr, "class2"));
	}

	/**
	 * test input with special chars
	 */
	public function testAttributeContainsSpecialChars()
	{
		$testAttr="--... %\$ä() /(=?}";
		$this->assertFalse(attribute_contains($testAttr, "class2"));
	}

	/**
	 * test expand_acl, perfect input
	 */
	public function testExpandAclNormal()
	{
		$text='<1><2><3>';
		$this->assertEquals(array(1, 2, 3), expand_acl($text));
	}

	/**
	 * test with a big number
	 */
	public function testExpandAclBigNumber()
	{
		$text='<1><'.PHP_INT_MAX.'><15>';
		$this->assertEquals(array(1, PHP_INT_MAX, 15), expand_acl($text));
	}

	/**
	 * test with a string in it.
	 *
	 * @todo is this valid input? Otherwise: should there be an exception?
	 */
	public function testExpandAclString()
	{
		$text="<1><279012><tt>";
		$this->assertEquals(array(1, 279012), expand_acl($text));
	}

	/**
	 * test with a ' ' in it.
	 *
	 * @todo is this valid input? Otherwise: should there be an exception?
	 */
	public function testExpandAclSpace()
	{
		$text="<1><279 012><32>";
		$this->assertEquals(array(1, "279", "32"), expand_acl($text));
	}

	/**
	 * test empty input
	 */
	public function testExpandAclEmpty()
	{
		$text="";
		$this->assertEquals(array(), expand_acl($text));
	}

	/**
	 * test invalid input, no < at all
	 *
	 * @todo should there be an exception?
	 */
	public function testExpandAclNoBrackets()
	{
		$text="According to documentation, that's invalid. "; //should be invalid
		$this->assertEquals(array(), expand_acl($text));
	}

	/**
	 * test invalid input, just open <
	 *
	 * @todo should there be an exception?
	 */
	public function testExpandAclJustOneBracket1()
	{
		$text="<Another invalid string"; //should be invalid
		$this->assertEquals(array(), expand_acl($text));
	}

	/**
	 * test invalid input, just close >
	 *
	 * @todo should there be an exception?
	 */
	public function testExpandAclJustOneBracket2()
	{
		$text="Another invalid> string"; //should be invalid
		$this->assertEquals(array(), expand_acl($text));
	}

	/**
	 * test invalid input, just close >
	 *
	 * @todo should there be an exception?
	 */
	public function testExpandAclCloseOnly()
	{
		$text="Another> invalid> string>"; //should be invalid
		$this->assertEquals(array(), expand_acl($text));
	}

	/**
	 * test invalid input, just open <
	 *
	 * @todo should there be an exception?
	 */
	public function testExpandAclOpenOnly()
	{
		$text="<Another< invalid string<"; //should be invalid
		$this->assertEquals(array(), expand_acl($text));
	}

	/**
	 * test invalid input, open and close do not match
	 *
	 * @todo should there be an exception?
	 */
	public function testExpandAclNoMatching1()
	{
		$text="<Another<> invalid <string>"; //should be invalid
		$this->assertEquals(array(), expand_acl($text));
	}

	/**
	 * test invalid input, empty <>
	 *
	 * @todo should there be an exception? Or array(1, 3)
	 * (This should be array(1,3) - mike)
	 */
	public function testExpandAclEmptyMatch()
	{
		$text="<1><><3>";
		$this->assertEquals(array(1,3), expand_acl($text));
	}

	/**
	 * test, that tags are escaped
	 */
	public function testEscapeTags()
	{
		$invalidstring='<submit type="button" onclick="alert(\'failed!\');" />';

		$validstring = Friendica\Util\Strings::removeTags($invalidstring);
		$escapedString = Friendica\Util\Strings::escapeTags($invalidstring);

		$this->assertEquals('[submit type="button" onclick="alert(\'failed!\');" /]', $validstring);
		$this->assertEquals(
			"&lt;submit type=&quot;button&quot; onclick=&quot;alert('failed!');&quot; /&gt;",
			$escapedString
		);
	}

	/**
	 * test hex2bin and reverse
	 */
	public function testHex2Bin()
	{
		$this->assertEquals(-3, hex2bin(bin2hex(-3)));
		$this->assertEquals(0, hex2bin(bin2hex(0)));
		$this->assertEquals(12, hex2bin(bin2hex(12)));
		$this->assertEquals(PHP_INT_MAX, hex2bin(bin2hex(PHP_INT_MAX)));
	}
}
