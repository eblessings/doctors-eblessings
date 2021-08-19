<?php
/**
 * @copyright Copyright (C) 2021, Friendica
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
namespace Friendica\Object\Log;

/**
 * Parse a log line and offer some utility methods
 */
class ParsedLog
{
	const REGEXP = '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[^ ]*) (\w+) \[(\w*)\]: (.*)/';

	public $id = 0;
	public $date = null;
	public $context = null;
	public $level = null;
	public $message = null;
	public $data = null;
	public $source = null;

	/**
	 * @param string $logline Source log line to parse
	 */
	public function __construct(int $id, string $logline)
	{
		$this->id = $id;
		$this->parse($logline);
		$this->stop = false;
	}

	private function parse($logline)
	{
		list($logline, $jsonsource) = explode(' - ', $logline);
		$jsondata = null;
		if (strpos($logline, '{"') > 0) {
			list($logline, $jsondata) = explode('{"', $logline, 2);
			$jsondata = '{"' . $jsondata;
		}
		preg_match(self::REGEXP, $logline, $matches);
		$this->date = $matches[1];
		$this->context = $matches[2];
		$this->level = $matches[3];
		$this->message = $matches[4];
		$this->data = $jsondata;
		$this->source = $jsonsource;
		$this->try_fix_json('data');
	}

	/**
	 * In log boundary between message and json data is not specified.
	 * If message  contains '{' the parser thinks there starts the json data.
	 * This method try to parse the found json and if it fails, search for next '{'
	 * in json data and retry
	 */
	private function try_fix_json(string $key)
	{
		if (is_null($this->$key) || $this->$key == "") {
			return;
		}
		try {
			$d = json_decode($this->$key, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			// try to find next { in $str and move string before to 'message'

			$pos = strpos($this->$key, '{', 1);

			$this->message .= substr($this->$key, 0, $pos);
			$this->$key = substr($this->key, $pos);
			$this->try_fix_json($key);
		}
	}

	/**
	 * Return decoded `data` as array suitable for template
	 *
	 * @return array
	 */
	public function get_data() {
		$data = json_decode($this->data, true);
		if ($data) {
			foreach($data as $k => $v) {
				$v = print_r($v, true);
				$data[$k] = $v;
			}
		}
		return $data;
	}

	/**
	 * Return decoded `source` as array suitable for template
	 *
	 * @return array
	 */
	public function get_source() {
		return json_decode($this->source, true);
	}
}
