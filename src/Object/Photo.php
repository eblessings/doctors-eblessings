<?php
/**
 * @file src/Object/Photo.php
 * @brief This file contains the Photo class for image processing
 */
namespace Friendica\Object;

use Friendica\App;
use Friendica\Core\Cache;
use Friendica\Core\Config;
use Friendica\Core\System;
use Friendica\Database\DBM;
use dba;
use Imagick;
use ImagickPixel;

require_once "include/photos.php";

/**
 * Class to handle Photos
 */
class Photo
{
	private $image;

	/*
	 * Put back gd stuff, not everybody have Imagick
	 */
	private $imagick;
	private $width;
	private $height;
	private $valid;
	private $type;
	private $types;

	/**
	 * @brief supported mimetypes and corresponding file extensions
	 * @return array
	 */
	public static function supportedTypes()
	{
		if (class_exists('Imagick')) {
			// Imagick::queryFormats won't help us a lot there...
			// At least, not yet, other parts of friendica uses this array
			$t = array(
				'image/jpeg' => 'jpg',
				'image/png' => 'png',
				'image/gif' => 'gif'
			);
		} else {
			$t = array();
			$t['image/jpeg'] ='jpg';
			if (imagetypes() & IMG_PNG) {
				$t['image/png'] = 'png';
			}
		}

		return $t;
	}

	/**
	 * @brief Constructor
	 * @param object  $data data
	 * @param boolean $type optional, default null
	 * @return object
	 */
	public function __construct($data, $type = null)
	{
		$this->imagick = class_exists('Imagick');
		$this->types = static::supportedTypes();
		if (!array_key_exists($type, $this->types)) {
			$type='image/jpeg';
		}
		$this->type = $type;

		if ($this->isImagick() && $this->loadData($data)) {
			return true;
		} else {
			// Failed to load with Imagick, fallback
			$this->imagick = false;
		}
		return $this->loadData($data);
	}

	/**
	 * @brief Destructor
	 * @return void
	 */
	public function __destruct()
	{
		if ($this->image) {
			if ($this->isImagick()) {
				$this->image->clear();
				$this->image->destroy();
				return;
			}
			if (is_resource($this->image)) {
				imagedestroy($this->image);
			}
		}
	}

	/**
	 * @return boolean
	 */
	public function isImagick()
	{
		return $this->imagick;
	}

	/**
	 * @brief Maps Mime types to Imagick formats
	 * @return arr With with image formats (mime type as key)
	 */
	public function getFormatsMap()
	{
		$m = array(
			'image/jpeg' => 'JPG',
			'image/png' => 'PNG',
			'image/gif' => 'GIF'
		);
		return $m;
	}

	/**
	 * @param object $data data
	 * @return boolean
	 */
	private function loadData($data)
	{
		if ($this->isImagick()) {
			$this->image = new Imagick();
			try {
				$this->image->readImageBlob($data);
			} catch (Exception $e) {
				// Imagick couldn't use the data
				return false;
			}

			/*
			 * Setup the image to the format it will be saved to
			 */
			$map = $this->getFormatsMap();
			$format = $map[$type];
			$this->image->setFormat($format);

			// Always coalesce, if it is not a multi-frame image it won't hurt anyway
			$this->image = $this->image->coalesceImages();

			/*
			 * setup the compression here, so we'll do it only once
			 */
			switch ($this->getType()) {
				case "image/png":
					$quality = Config::get('system', 'png_quality');
					if ((! $quality) || ($quality > 9)) {
						$quality = PNG_QUALITY;
					}
					/*
					 * From http://www.imagemagick.org/script/command-line-options.php#quality:
					 *
					 * 'For the MNG and PNG image formats, the quality value sets
					 * the zlib compression level (quality / 10) and filter-type (quality % 10).
					 * The default PNG "quality" is 75, which means compression level 7 with adaptive PNG filtering,
					 * unless the image has a color map, in which case it means compression level 7 with no PNG filtering'
					 */
					$quality = $quality * 10;
					$this->image->setCompressionQuality($quality);
					break;
				case "image/jpeg":
					$quality = Config::get('system', 'jpeg_quality');
					if ((! $quality) || ($quality > 100)) {
						$quality = JPEG_QUALITY;
					}
					$this->image->setCompressionQuality($quality);
			}

			// The 'width' and 'height' properties are only used by non-Imagick routines.
			$this->width  = $this->image->getImageWidth();
			$this->height = $this->image->getImageHeight();
			$this->valid  = true;

			return true;
		}

		$this->valid = false;
		$this->image = @imagecreatefromstring($data);
		if ($this->image !== false) {
			$this->width  = imagesx($this->image);
			$this->height = imagesy($this->image);
			$this->valid  = true;
			imagealphablending($this->image, false);
			imagesavealpha($this->image, true);

			return true;
		}

		return false;
	}

	/**
	 * @return boolean
	 */
	public function isValid()
	{
		if ($this->isImagick()) {
			return ($this->image !== false);
		}
		return $this->valid;
	}

	/**
	 * @return mixed
	 */
	public function getWidth()
	{
		if (!$this->isValid()) {
			return false;
		}

		if ($this->isImagick()) {
			return $this->image->getImageWidth();
		}
		return $this->width;
	}

	/**
	 * @return mixed
	 */
	public function getHeight()
	{
		if (!$this->isValid()) {
			return false;
		}

		if ($this->isImagick()) {
			return $this->image->getImageHeight();
		}
		return $this->height;
	}

	/**
	 * @return mixed
	 */
	public function getImage()
	{
		if (!$this->isValid()) {
			return false;
		}

		if ($this->isImagick()) {
			/* Clean it */
			$this->image = $this->image->deconstructImages();
			return $this->image;
		}
		return $this->image;
	}

	/**
	 * @return mixed
	 */
	public function getType()
	{
		if (!$this->isValid()) {
			return false;
		}

		return $this->type;
	}

	/**
	 * @return mixed
	 */
	public function getExt()
	{
		if (!$this->isValid()) {
			return false;
		}

		return $this->types[$this->getType()];
	}

	/**
	 * @param integer $max max dimension
	 * @return mixed
	 */
	public function scaleImage($max)
	{
		if (!$this->isValid()) {
			return false;
		}

		$width = $this->getWidth();
		$height = $this->getHeight();

		$dest_width = $dest_height = 0;

		if ((! $width)|| (! $height)) {
			return false;
		}

		if ($width > $max && $height > $max) {
			// very tall image (greater than 16:9)
			// constrain the width - let the height float.

			if ((($height * 9) / 16) > $width) {
				$dest_width = $max;
				$dest_height = intval(($height * $max) / $width);
			} elseif ($width > $height) {
				// else constrain both dimensions
				$dest_width = $max;
				$dest_height = intval(($height * $max) / $width);
			} else {
				$dest_width = intval(($width * $max) / $height);
				$dest_height = $max;
			}
		} else {
			if ($width > $max) {
				$dest_width = $max;
				$dest_height = intval(($height * $max) / $width);
			} else {
				if ($height > $max) {
					// very tall image (greater than 16:9)
					// but width is OK - don't do anything

					if ((($height * 9) / 16) > $width) {
						$dest_width = $width;
						$dest_height = $height;
					} else {
						$dest_width = intval(($width * $max) / $height);
						$dest_height = $max;
					}
				} else {
					$dest_width = $width;
					$dest_height = $height;
				}
			}
		}


		if ($this->isImagick()) {
			/*
			 * If it is not animated, there will be only one iteration here,
			 * so don't bother checking
			 */
			// Don't forget to go back to the first frame
			$this->image->setFirstIterator();
			do {
				// FIXME - implement horizantal bias for scaling as in followin GD functions
				// to allow very tall images to be constrained only horizontally.

				$this->image->scaleImage($dest_width, $dest_height);
			} while ($this->image->nextImage());

			// These may not be necessary any more
			$this->width  = $this->image->getImageWidth();
			$this->height = $this->image->getImageHeight();

			return;
		}


		$dest = imagecreatetruecolor($dest_width, $dest_height);
		imagealphablending($dest, false);
		imagesavealpha($dest, true);
		if ($this->type=='image/png') {
			imagefill($dest, 0, 0, imagecolorallocatealpha($dest, 0, 0, 0, 127)); // fill with alpha
		}
		imagecopyresampled($dest, $this->image, 0, 0, 0, 0, $dest_width, $dest_height, $width, $height);
		if ($this->image) {
			imagedestroy($this->image);
		}
		$this->image = $dest;
		$this->width  = imagesx($this->image);
		$this->height = imagesy($this->image);
	}

	/**
	 * @param integer $degrees degrees to rotate image
	 * @return mixed
	 */
	public function rotate($degrees)
	{
		if (!$this->isValid()) {
			return false;
		}

		if ($this->isImagick()) {
			$this->image->setFirstIterator();
			do {
				$this->image->rotateImage(new ImagickPixel(), -$degrees); // ImageMagick rotates in the opposite direction of imagerotate()
			} while ($this->image->nextImage());
			return;
		}

		// if script dies at this point check memory_limit setting in php.ini
		$this->image  = imagerotate($this->image, $degrees, 0);
		$this->width  = imagesx($this->image);
		$this->height = imagesy($this->image);
	}

	/**
	 * @param boolean $horiz optional, default true
	 * @param boolean $vert  optional, default false
	 * @return mixed
	 */
	public function flip($horiz = true, $vert = false)
	{
		if (!$this->isValid()) {
			return false;
		}

		if ($this->isImagick()) {
			$this->image->setFirstIterator();
			do {
				if ($horiz) {
					$this->image->flipImage();
				}
				if ($vert) {
					$this->image->flopImage();
				}
			} while ($this->image->nextImage());
			return;
		}

		$w = imagesx($this->image);
		$h = imagesy($this->image);
		$flipped = imagecreate($w, $h);
		if ($horiz) {
			for ($x = 0; $x < $w; $x++) {
				imagecopy($flipped, $this->image, $x, 0, $w - $x - 1, 0, 1, $h);
			}
		}
		if ($vert) {
			for ($y = 0; $y < $h; $y++) {
				imagecopy($flipped, $this->image, 0, $y, 0, $h - $y - 1, $w, 1);
			}
		}
		$this->image = $flipped;
	}

	/**
	 * @param string $filename filename
	 * @return mixed
	 */
	public function orient($filename)
	{
		if ($this->isImagick()) {
			// based off comment on http://php.net/manual/en/imagick.getimageorientation.php
			$orientation = $this->image->getImageOrientation();
			switch ($orientation) {
				case Imagick::ORIENTATION_BOTTOMRIGHT:
					$this->image->rotateimage("#000", 180);
					break;
				case Imagick::ORIENTATION_RIGHTTOP:
					$this->image->rotateimage("#000", 90);
					break;
				case Imagick::ORIENTATION_LEFTBOTTOM:
					$this->image->rotateimage("#000", -90);
					break;
			}

			$this->image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
			return true;
		}
		// based off comment on http://php.net/manual/en/function.imagerotate.php

		if (!$this->isValid()) {
			return false;
		}

		if ((!function_exists('exif_read_data')) || ($this->getType() !== 'image/jpeg')) {
			return;
		}

		$exif = @exif_read_data($filename, null, true);
		if (!$exif) {
			return;
		}

		$ort = $exif['IFD0']['Orientation'];

		switch ($ort) {
			case 1: // nothing
				break;

			case 2: // horizontal flip
				$this->flip();
				break;

			case 3: // 180 rotate left
				$this->rotate(180);
				break;

			case 4: // vertical flip
				$this->flip(false, true);
				break;

			case 5: // vertical flip + 90 rotate right
				$this->flip(false, true);
				$this->rotate(-90);
				break;

			case 6: // 90 rotate right
				$this->rotate(-90);
				break;

			case 7: // horizontal flip + 90 rotate right
				$this->flip();
				$this->rotate(-90);
				break;

			case 8: // 90 rotate left
				$this->rotate(90);
				break;
		}

		//	logger('exif: ' . print_r($exif,true));
		return $exif;
	}

	/**
	 * @param integer $min minimum dimension
	 * @return mixed
	 */
	public function scaleImageUp($min)
	{
		if (!$this->isValid()) {
			return false;
		}

		$width = $this->getWidth();
		$height = $this->getHeight();

		$dest_width = $dest_height = 0;

		if ((!$width)|| (!$height)) {
			return false;
		}

		if ($width < $min && $height < $min) {
			if ($width > $height) {
				$dest_width = $min;
				$dest_height = intval(($height * $min) / $width);
			} else {
				$dest_width = intval(($width * $min) / $height);
				$dest_height = $min;
			}
		} else {
			if ($width < $min) {
				$dest_width = $min;
				$dest_height = intval(($height * $min) / $width);
			} else {
				if ($height < $min) {
					$dest_width = intval(($width * $min) / $height);
					$dest_height = $min;
				} else {
					$dest_width = $width;
					$dest_height = $height;
				}
			}
		}

		if ($this->isImagick()) {
			return $this->scaleImage($dest_width, $dest_height);
		}

		$dest = imagecreatetruecolor($dest_width, $dest_height);
		imagealphablending($dest, false);
		imagesavealpha($dest, true);
		if ($this->type=='image/png') {
			imagefill($dest, 0, 0, imagecolorallocatealpha($dest, 0, 0, 0, 127)); // fill with alpha
		}
		imagecopyresampled($dest, $this->image, 0, 0, 0, 0, $dest_width, $dest_height, $width, $height);
		if ($this->image) {
			imagedestroy($this->image);
		}
		$this->image = $dest;
		$this->width  = imagesx($this->image);
		$this->height = imagesy($this->image);
	}

	/**
	 * @param integer $dim dimension
	 * @return mixed
	 */
	public function scaleImageSquare($dim)
	{
		if (!$this->isValid()) {
			return false;
		}

		if ($this->isImagick()) {
			$this->image->setFirstIterator();
			do {
				$this->image->scaleImage($dim, $dim);
			} while ($this->image->nextImage());
			return;
		}

		$dest = imagecreatetruecolor($dim, $dim);
		imagealphablending($dest, false);
		imagesavealpha($dest, true);
		if ($this->type=='image/png') {
			imagefill($dest, 0, 0, imagecolorallocatealpha($dest, 0, 0, 0, 127)); // fill with alpha
		}
		imagecopyresampled($dest, $this->image, 0, 0, 0, 0, $dim, $dim, $this->width, $this->height);
		if ($this->image) {
			imagedestroy($this->image);
		}
		$this->image = $dest;
		$this->width  = imagesx($this->image);
		$this->height = imagesy($this->image);
	}

	/**
	 * @param integer $max maximum
	 * @param integer $x   x coordinate
	 * @param integer $y   y coordinate
	 * @param integer $w   width
	 * @param integer $h   height
	 * @return mixed
	 */
	public function cropImage($max, $x, $y, $w, $h)
	{
		if (!$this->isValid()) {
			return false;
		}

		if ($this->isImagick()) {
			$this->image->setFirstIterator();
			do {
				$this->image->cropImage($w, $h, $x, $y);
				/*
				 * We need to remove the canva,
				 * or the image is not resized to the crop:
				 * http://php.net/manual/en/imagick.cropimage.php#97232
				 */
				$this->image->setImagePage(0, 0, 0, 0);
			} while ($this->image->nextImage());
			return $this->scaleImage($max);
		}

		$dest = imagecreatetruecolor($max, $max);
		imagealphablending($dest, false);
		imagesavealpha($dest, true);
		if ($this->type=='image/png') {
			imagefill($dest, 0, 0, imagecolorallocatealpha($dest, 0, 0, 0, 127)); // fill with alpha
		}
		imagecopyresampled($dest, $this->image, 0, 0, $x, $y, $max, $max, $w, $h);
		if ($this->image) {
			imagedestroy($this->image);
		}
		$this->image = $dest;
		$this->width  = imagesx($this->image);
		$this->height = imagesy($this->image);
	}

	/**
	 * @param string $path file path
	 * @return mixed
	 */
	public function saveImage($path)
	{
		if (!$this->isValid()) {
			return false;
		}

		$string = $this->imageString();

		$a = get_app();

		$stamp1 = microtime(true);
		file_put_contents($path, $string);
		$a->save_timestamp($stamp1, "file");
	}

	/**
	 * @return mixed
	 */
	public function imageString()
	{
		if (!$this->isValid()) {
			return false;
		}

		if ($this->isImagick()) {
			/* Clean it */
			$this->image = $this->image->deconstructImages();
			$string = $this->image->getImagesBlob();
			return $string;
		}

		$quality = false;

		ob_start();

		// Enable interlacing
		imageinterlace($this->image, true);

		switch ($this->getType()) {
			case "image/png":
				$quality = Config::get('system', 'png_quality');
				if ((!$quality) || ($quality > 9)) {
					$quality = PNG_QUALITY;
				}
				imagepng($this->image, null, $quality);
				break;
			case "image/jpeg":
				$quality = Config::get('system', 'jpeg_quality');
				if ((!$quality) || ($quality > 100)) {
					$quality = JPEG_QUALITY;
				}
				imagejpeg($this->image, null, $quality);
		}
		$string = ob_get_contents();
		ob_end_clean();

		return $string;
	}

	/**
	 * @param integer $uid       uid
	 * @param integer $cid       cid
	 * @param integer $rid       rid
	 * @param string  $filename  filename
	 * @param string  $album     album name
	 * @param integer $scale     scale
	 * @param integer $profile   optional, default = 0
	 * @param string  $allow_cid optional, default = ''
	 * @param string  $allow_gid optional, default = ''
	 * @param string  $deny_cid  optional, default = ''
	 * @param string  $deny_gid  optional, default = ''
	 * @param string  $desc      optional, default = ''
	 * @return object
	 */
	public function store($uid, $cid, $rid, $filename, $album, $scale, $profile = 0, $allow_cid = '', $allow_gid = '', $deny_cid = '', $deny_gid = '', $desc = '')
	{
		$r = dba::select('photo', array('guid'), array("`resource-id` = ? AND `guid` != ?", $rid, ''), array('limit' => 1));
		if (DBM::is_result($r)) {
			$guid = $r['guid'];
		} else {
			$guid = get_guid();
		}

		$x = dba::select('photo', array('id'), array('resource-id' => $rid, 'uid' => $uid, 'contact-id' => $cid, 'scale' => $scale), array('limit' => 1));

		$fields = array('uid' => $uid, 'contact-id' => $cid, 'guid' => $guid, 'resource-id' => $rid, 'created' => datetime_convert(), 'edited' => datetime_convert(),
				'filename' => basename($filename), 'type' => $this->getType(), 'album' => $album, 'height' => $this->getHeight(), 'width' => $this->getWidth(),
				'datasize' => strlen($this->imageString()), 'data' => $this->imageString(), 'scale' => $scale, 'profile' => $profile,
				'allow_cid' => $allow_cid, 'allow_gid' => $allow_gid, 'deny_cid' => $deny_cid, 'deny_gid' => $deny_gid, 'desc' => $desc);

		if (DBM::is_result($x)) {
			$r = dba::update('photo', $fields, array('id' => $x['id']));
		} else {
			$r = dba::insert('photo', $fields);
		}

		return $r;
	}

	/**
	 * Guess image mimetype from filename or from Content-Type header
	 *
	 * @param string  $filename Image filename
	 * @param boolean $fromcurl Check Content-Type header from curl request
	 *
	 * @return object
	 */
	public function guessImageType($filename, $fromcurl = false)
	{
		logger('Photo: guessImageType: '.$filename . ($fromcurl?' from curl headers':''), LOGGER_DEBUG);
		$type = null;
		if ($fromcurl) {
			$a = get_app();
			$headers=array();
			$h = explode("\n", $a->get_curl_headers());
			foreach ($h as $l) {
				list($k,$v) = array_map("trim", explode(":", trim($l), 2));
				$headers[$k] = $v;
			}
			if (array_key_exists('Content-Type', $headers))
				$type = $headers['Content-Type'];
		}
		if (is_null($type)) {
			// Guessing from extension? Isn't that... dangerous?
			if (class_exists('Imagick') && file_exists($filename) && is_readable($filename)) {
				/**
				 * Well, this not much better,
				 * but at least it comes from the data inside the image,
				 * we won't be tricked by a manipulated extension
				 */
				$image = new Imagick($filename);
				$type = $image->getImageMimeType();
				$image->setInterlaceScheme(Imagick::INTERLACE_PLANE);
			} else {
				$ext = pathinfo($filename, PATHINFO_EXTENSION);
				$types = $this->supportedTypes();
				$type = "image/jpeg";
				foreach ($types as $m => $e) {
					if ($ext == $e) {
						$type = $m;
					}
				}
			}
		}
		logger('Photo: guessImageType: type='.$type, LOGGER_DEBUG);
		return $type;
	}

	/**
	 * @param string  $photo         photo
	 * @param integer $uid           user id
	 * @param integer $cid           contact id
	 * @param boolean $quit_on_error optional, default false
	 * @return array
	 */
	private function importProfilePhoto($photo, $uid, $cid, $quit_on_error = false)
	{
		$r = dba::select(
			'photo',
			array('resource-id'),
			array('uid' => $uid, 'contact-id' => $cid, 'scale' => 4, 'album' => 'Contact Photos'),
			array('limit' => 1)
		);

		if (DBM::is_result($r) && strlen($r['resource-id'])) {
			$hash = $r['resource-id'];
		} else {
			$hash = photo_new_resource();
		}
	
		$photo_failure = false;
	
		$filename = basename($photo);
		$img_str = fetch_url($photo, true);
	
		if ($quit_on_error && ($img_str == "")) {
			return false;
		}
	
		$type = $this->guessImageType($photo, true);
		$img = new Photo($img_str, $type);
		if ($img->isValid()) {
			$img->scaleImageSquare(175);
	
			$r = $img->store($uid, $cid, $hash, $filename, 'Contact Photos', 4);
	
			if ($r === false) {
				$photo_failure = true;
			}
	
			$img->scaleImage(80);
	
			$r = $img->store($uid, $cid, $hash, $filename, 'Contact Photos', 5);
	
			if ($r === false) {
				$photo_failure = true;
			}
	
			$img->scaleImage(48);
	
			$r = $img->store($uid, $cid, $hash, $filename, 'Contact Photos', 6);
	
			if ($r === false) {
				$photo_failure = true;
			}
	
			$suffix = '?ts='.time();
	
			$photo = System::baseUrl() . '/photo/' . $hash . '-4.' . $img->getExt() . $suffix;
			$thumb = System::baseUrl() . '/photo/' . $hash . '-5.' . $img->getExt() . $suffix;
			$micro = System::baseUrl() . '/photo/' . $hash . '-6.' . $img->getExt() . $suffix;
	
			// Remove the cached photo
			$a = get_app();
			$basepath = $a->get_basepath();
	
			if (is_dir($basepath."/photo")) {
				$filename = $basepath.'/photo/'.$hash.'-4.'.$img->getExt();
				if (file_exists($filename)) {
					unlink($filename);
				}
				$filename = $basepath.'/photo/'.$hash.'-5.'.$img->getExt();
				if (file_exists($filename)) {
					unlink($filename);
				}
				$filename = $basepath.'/photo/'.$hash.'-6.'.$img->getExt();
				if (file_exists($filename)) {
					unlink($filename);
				}
			}
		} else {
			$photo_failure = true;
		}
	
		if ($photo_failure && $quit_on_error) {
			return false;
		}
	
		if ($photo_failure) {
			$photo = System::baseUrl() . '/images/person-175.jpg';
			$thumb = System::baseUrl() . '/images/person-80.jpg';
			$micro = System::baseUrl() . '/images/person-48.jpg';
		}
	
		return array($photo, $thumb, $micro);
	}

	/**
	 * @param string $url url
	 * @return object
	 */
	public function getInfoFromURL($url)
	{
		$data = array();
	
		$data = Cache::get($url);
	
		if (is_null($data) || !$data || !is_array($data)) {
			$img_str = fetch_url($url, true, $redirects, 4);
			$filesize = strlen($img_str);
	
			if (function_exists("getimagesizefromstring")) {
				$data = getimagesizefromstring($img_str);
			} else {
				$tempfile = tempnam(get_temppath(), "cache");
	
				$a = get_app();
				$stamp1 = microtime(true);
				file_put_contents($tempfile, $img_str);
				$a->save_timestamp($stamp1, "file");
	
				$data = getimagesize($tempfile);
				unlink($tempfile);
			}
	
			if ($data) {
				$data["size"] = $filesize;
			}
	
			Cache::set($url, $data);
		}
	
		return $data;
	}

	/**
	 * @param integer $width  width
	 * @param integer $height height
	 * @param integer $max    max
	 * @return array
	 */
	public function scaleImageTo($width, $height, $max)
	{
		$dest_width = $dest_height = 0;
	
		if ((!$width) || (!$height)) {
			return false;
		}
	
		if ($width > $max && $height > $max) {
			// very tall image (greater than 16:9)
			// constrain the width - let the height float.
	
			if ((($height * 9) / 16) > $width) {
				$dest_width = $max;
				$dest_height = intval(($height * $max) / $width);
			} elseif ($width > $height) {
				// else constrain both dimensions
				$dest_width = $max;
				$dest_height = intval(($height * $max) / $width);
			} else {
				$dest_width = intval(($width * $max) / $height);
				$dest_height = $max;
			}
		} else {
			if ($width > $max) {
				$dest_width = $max;
				$dest_height = intval(($height * $max) / $width);
			} else {
				if ($height > $max) {
					// very tall image (greater than 16:9)
					// but width is OK - don't do anything
	
					if ((($height * 9) / 16) > $width) {
						$dest_width = $width;
						$dest_height = $height;
					} else {
						$dest_width = intval(($width * $max) / $height);
						$dest_height = $max;
					}
				} else {
					$dest_width = $width;
					$dest_height = $height;
				}
			}
		}
		return array("width" => $dest_width, "height" => $dest_height);
	}
}
