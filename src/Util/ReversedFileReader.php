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

namespace Friendica\Util;


/**
 * An iterator which returns lines from file in reversed order
 *
 * original code https://stackoverflow.com/a/10494801
 */
class ReversedFileReader implements \Iterator
{
    const BUFFER_SIZE = 4096;
    const SEPARATOR = "\n";

    public function __construct($filename)
    {
        $this->_fh = fopen($filename, 'r');
		if (!$this->_fh) {
			// this should use a custom exception.
			throw \Exception("Unable to open $filename");
		}
        $this->_filesize = filesize($filename);
        $this->_pos = -1;
        $this->_buffer = null;
        $this->_key = -1;
        $this->_value = null;
    }

    public function _read($size)
    {
        $this->_pos -= $size;
        fseek($this->_fh, $this->_pos);
        return fread($this->_fh, $size);
    }

    public function _readline()
    {
        $buffer =& $this->_buffer;
        while (true) {
            if ($this->_pos == 0) {
                return array_pop($buffer);
            }
            if (count($buffer) > 1) {
                return array_pop($buffer);
            }
            $buffer = explode(self::SEPARATOR, $this->_read(self::BUFFER_SIZE) . $buffer[0]);
        }
    }

    public function next()
    {
        ++$this->_key;
        $this->_value = $this->_readline();
    }

    public function rewind()
    {
        if ($this->_filesize > 0) {
            $this->_pos = $this->_filesize;
            $this->_value = null;
            $this->_key = -1;
            $this->_buffer = explode(self::SEPARATOR, $this->_read($this->_filesize % self::BUFFER_SIZE ?: self::BUFFER_SIZE));
            $this->next();
        }
    }

    public function key()
	{
		return $this->_key;
	}

    public function current()
	{
		return $this->_value;
	}

    public function valid()
	{
		return ! is_null($this->_value);
	}
}
