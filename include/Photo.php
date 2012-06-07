<?php

if(! class_exists("Photo")) {
class Photo {

	private $image;
	private $width;
	private $height;
	private $valid;
	private $type;
	private $types;

	/**
	 * supported mimetypes and corresponding file extensions
	 */
	static function supportedTypes() {
		$t = array();
		$t['image/jpeg'] ='jpg';
		if (imagetypes() & IMG_PNG) $t['image/png'] = 'png';
		return $t;
	}

	public function __construct($data, $type="image/jpeg") {

		$this->types = $this->supportedTypes();
		if (!array_key_exists($type,$this->types)){
			$type='image/jpeg';
		}
		$this->valid = false;
		$this->type = $type;
		$this->image = @imagecreatefromstring($data);
		if($this->image !== FALSE) {
			$this->width  = imagesx($this->image);
			$this->height = imagesy($this->image);
			$this->valid  = true;
			imagealphablending($this->image, false);
			imagesavealpha($this->image, true);
		}
	}

	public function __destruct() {
		if($this->image)
			imagedestroy($this->image);
	}

	public function is_valid() {
		return $this->valid;
	}

	public function getWidth() {
		return $this->width;
	}

	public function getHeight() {
		return $this->height;
	}

	public function getImage() {
		return $this->image;
	}
	
	public function getType() {
		return $this->type;
	}
	public function getExt() {
		return $this->types[$this->type];
	}

	public function scaleImage($max) {

		$width = $this->width;
		$height = $this->height;

		$dest_width = $dest_height = 0;

		if((! $width)|| (! $height))
			return FALSE;

		if($width > $max && $height > $max) {
			if($width > $height) {
				$dest_width = $max;
				$dest_height = intval(( $height * $max ) / $width);
			}
			else {
				$dest_width = intval(( $width * $max ) / $height);
				$dest_height = $max;
			}
		}
		else {
			if( $width > $max ) {
				$dest_width = $max;
				$dest_height = intval(( $height * $max ) / $width);
			}
			else {
				if( $height > $max ) {
					$dest_width = intval(( $width * $max ) / $height);
					$dest_height = $max;
				}
				else {
					$dest_width = $width;
					$dest_height = $height;
				}
			}
		}


		$dest = imagecreatetruecolor( $dest_width, $dest_height );
		imagealphablending($dest->image, false);
		imagesavealpha($dest, true);
		if ($this->type=='image/png') imagefill($dest, 0, 0, imagecolorallocatealpha($dest, 0, 0, 0, 127));; // fill with alpha
		imagecopyresampled($dest, $this->image, 0, 0, 0, 0, $dest_width, $dest_height, $width, $height);
		if($this->image)
			imagedestroy($this->image);
		$this->image = $dest;
		$this->width  = imagesx($this->image);
		$this->height = imagesy($this->image);

	}

	public function rotate($degrees) {
		$this->image  = imagerotate($this->image,$degrees,0);
		$this->width  = imagesx($this->image);
		$this->height = imagesy($this->image);
	}	



	public function scaleImageUp($min) {

		$width = $this->width;
		$height = $this->height;

		$dest_width = $dest_height = 0;

		if((! $width)|| (! $height))
			return FALSE;

		if($width < $min && $height < $min) {
			if($width > $height) {
				$dest_width = $min;
				$dest_height = intval(( $height * $min ) / $width);
			}
			else {
				$dest_width = intval(( $width * $min ) / $height);
				$dest_height = $min;
			}
		}
		else {
			if( $width < $min ) {
				$dest_width = $min;
				$dest_height = intval(( $height * $min ) / $width);
			}
			else {
				if( $height < $min ) {
					$dest_width = intval(( $width * $min ) / $height);
					$dest_height = $min;
				}
				else {
					$dest_width = $width;
					$dest_height = $height;
				}
			}
		}


		$dest = imagecreatetruecolor( $dest_width, $dest_height );
		imagealphablending($dest->image, false);
		imagesavealpha($dest, true);
		if ($this->type=='image/png') imagefill($dest, 0, 0, imagecolorallocatealpha($dest, 0, 0, 0, 127));; // fill with alpha
		imagecopyresampled($dest, $this->image, 0, 0, 0, 0, $dest_width, $dest_height, $width, $height);
		if($this->image)
			imagedestroy($this->image);
		$this->image = $dest;
		$this->width  = imagesx($this->image);
		$this->height = imagesy($this->image);

	}



	public function scaleImageSquare($dim) {

		$dest = imagecreatetruecolor( $dim, $dim );
		imagealphablending($dest->image, false);
		imagesavealpha($dest, true);
		if ($this->type=='image/png') imagefill($dest, 0, 0, imagecolorallocatealpha($dest, 0, 0, 0, 127));; // fill with alpha
		imagecopyresampled($dest, $this->image, 0, 0, 0, 0, $dim, $dim, $this->width, $this->height);
		if($this->image)
			imagedestroy($this->image);
		$this->image = $dest;
		$this->width  = imagesx($this->image);
		$this->height = imagesy($this->image);
	}


	public function cropImage($max,$x,$y,$w,$h) {
		$dest = imagecreatetruecolor( $max, $max );
		imagealphablending($dest->image, false);
		imagesavealpha($dest, true);
		if ($this->type=='image/png') imagefill($dest, 0, 0, imagecolorallocatealpha($dest, 0, 0, 0, 127));; // fill with alpha
		imagecopyresampled($dest, $this->image, 0, 0, $x, $y, $max, $max, $w, $h);
		if($this->image)
			imagedestroy($this->image);
		$this->image = $dest;
		$this->width  = imagesx($this->image);
		$this->height = imagesy($this->image);
	}

	public function saveImage($path) {
		switch($this->type){
			case "image/png":
				$quality = get_config('system','png_quality');
				if((! $quality) || ($quality > 9))
					$quality = PNG_QUALITY;
				imagepng($this->image, $path, $quality);
				break;
			default:
				$quality = get_config('system','jpeg_quality');
				if((! $quality) || ($quality > 100))
					$quality = JPEG_QUALITY;
				imagejpeg($this->image,$path,$quality);
		}
		
	}

	public function imageString() {
		ob_start();
		switch($this->type){
			case "image/png":
				$quality = get_config('system','png_quality');
				if((! $quality) || ($quality > 9))
					$quality = PNG_QUALITY;
				imagepng($this->image,NULL, $quality);
				break;
			default:
				$quality = get_config('system','jpeg_quality');
				if((! $quality) || ($quality > 100))
					$quality = JPEG_QUALITY;

				imagejpeg($this->image,NULL,$quality);
		}
		$s = ob_get_contents();
		ob_end_clean();
		return $s;
	}



	public function store($uid, $cid, $rid, $filename, $album, $scale, $profile = 0, $allow_cid = '', $allow_gid = '', $deny_cid = '', $deny_gid = '') {

		$r = q("select `guid` from photo where `resource-id` = '%s' and `guid` != '' limit 1",
			dbesc($rid)
		);
		if(count($r))
			$guid = $r[0]['guid'];
		else
			$guid = get_guid();

		$r = q("INSERT INTO `photo`
			( `uid`, `contact-id`, `guid`, `resource-id`, `created`, `edited`, `filename`, type, `album`, `height`, `width`, `data`, `scale`, `profile`, `allow_cid`, `allow_gid`, `deny_cid`, `deny_gid` )
			VALUES ( %d, %d, '%s', '%s', '%s', '%s', '%s', '%s', '%s', %d, %d, '%s', %d, %d, '%s', '%s', '%s', '%s' )",
			intval($uid),
			intval($cid),
			dbesc($guid),
			dbesc($rid),
			dbesc(datetime_convert()),
			dbesc(datetime_convert()),
			dbesc(basename($filename)),
			dbesc($this->type),
			dbesc($album),
			intval($this->height),
			intval($this->width),
			dbesc($this->imageString()),
			intval($scale),
			intval($profile),
			dbesc($allow_cid),
			dbesc($allow_gid),
			dbesc($deny_cid),
			dbesc($deny_gid)
		);
		return $r;
	}





}}


/**
 * Guess image mimetype from filename or from Content-Type header
 * 
 * @arg $filename string Image filename
 * @arg $fromcurl boolean Check Content-Type header from curl request
 */
function guess_image_type($filename, $fromcurl=false) {
	$type = null;
	if ($fromcurl) {
		$a = get_app(); 
		$headers=array();
		$h = explode("\n",$a->get_curl_headers());
		foreach ($h as $l) {
			list($k,$v) = array_map("trim", explode(":", trim($l), 2));
			$headers[$k] = $v;
		}
		if (array_key_exists('Content-Type', $headers))
			$type = $headers['Content-Type'];
	}
	if (is_null($type)){
		$ext = pathinfo($filename, PATHINFO_EXTENSION);
		$types = Photo::supportedTypes();
		foreach ($types as $m=>$e){
			if ($ext==$e) return $m;
		}
		$type = "image/jpeg";
	}

	return $type;
	
}

function import_profile_photo($photo,$uid,$cid) {

	$a = get_app();

	$photo_failure = false;

	$filename = basename($photo);
	$img_str = fetch_url($photo,true);
	
	// guess mimetype from headers or filename
	$type = guess_image_type($photo,true);

	
	$img = new Photo($img_str, $type);
	if($img->is_valid()) {

		$img->scaleImageSquare(175);
					
		$hash = photo_new_resource();

		$r = $img->store($uid, $cid, $hash, $filename, 'Contact Photos', 4 );

		if($r === false)
			$photo_failure = true;

		$img->scaleImage(80);

		$r = $img->store($uid, $cid, $hash, $filename, 'Contact Photos', 5 );

		if($r === false)
			$photo_failure = true;

		$img->scaleImage(48);

		$r = $img->store($uid, $cid, $hash, $filename, 'Contact Photos', 6 );

		if($r === false)
			$photo_failure = true;



		$photo = $a->get_baseurl() . '/photo/' . $hash . '-4.' . $img->getExt();
		$thumb = $a->get_baseurl() . '/photo/' . $hash . '-5.' . $img->getExt();
		$micro = $a->get_baseurl() . '/photo/' . $hash . '-6.' . $img->getExt();
	}
	else
		$photo_failure = true;

	if($photo_failure) {
		$photo = $a->get_baseurl() . '/images/person-175.jpg';
		$thumb = $a->get_baseurl() . '/images/person-80.jpg';
		$micro = $a->get_baseurl() . '/images/person-48.jpg';
	}

	return(array($photo,$thumb,$micro));

}
