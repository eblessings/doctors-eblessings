<?php
/**
 * @file src/Module/Proxy.php
 * @brief Based upon "Privacy Image Cache" by Tobias Hößl <https://github.com/CatoTH/>
 */
namespace Friendica\Module;

use Friendica\App;
use Friendica\BaseModule;
use Friendica\Core\Config;
use Friendica\Core\L10n;
use Friendica\Core\System;
use Friendica\Database\DBA;
use Friendica\Model\Photo;
use Friendica\Object\Image;
use Friendica\Util\DateTimeFormat;
use Friendica\Util\Network;
use Friendica\Util\Proxy as ProxyUtils;

/**
 * @brief Module Proxy
 *
 * urls:
 * /proxy/[sub1/[sub2/]]<base64url image url>[.ext][:size]
 * /proxy?url=<image url>
 */
class Proxy extends BaseModule
{

	/**
	 * @brief Initializer method for this class.
	 *
	 * Sets application instance and checks if /proxy/ path is writable.
	 *
	 * @param \Friendica\App $app Application instance
	 */
	public static function init()
	{
		// Set application instance here
		$a = self::getApp();

		/*
		 * Pictures are stored in one of the following ways:
		 *
		 * 1. If a folder "proxy" exists and is writeable, then use this for caching
		 * 2. If a cache path is defined, use this
		 * 3. If everything else failed, cache into the database
		 *
		 * Question: Do we really need these three methods?
		 */
		if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
			header('HTTP/1.1 304 Not Modified');
			header('Last-Modified: ' . gmdate('D, d M Y H:i:s', time()) . ' GMT');
			header('Etag: ' . $_SERVER['HTTP_IF_NONE_MATCH']);
			header('Expires: ' . gmdate('D, d M Y H:i:s', time() + (31536000)) . ' GMT');
			header('Cache-Control: max-age=31536000');

			if (function_exists('header_remove')) {
				header_remove('Last-Modified');
				header_remove('Expires');
				header_remove('Cache-Control');
			}

			/// @TODO Stop here?
			exit();
		}

		if (function_exists('header_remove')) {
			header_remove('Pragma');
			header_remove('pragma');
		}

		$direct_cache = self::setupDirectCache();

		$request = self::getRequestInfo();

		if (empty($request['url'])) {
			System::httpExit(400, ['title' => L10n::t('Bad Request.')]);
		}

		// Webserver already tried direct cache...

		// Try to use filecache;
		$cachefile = self::responseFromCache($request);

		// Try to use photo from db
		self::responseFromDB($request);


		//
		// If script is here, the requested url has never cached before.
		// Let's fetch it, scale it if required, then save it in cache.
		//


		// It shouldn't happen but it does - spaces in URL
		$request['url'] = str_replace(' ', '+', $request['url']);
		$redirects = 0;
		$fetchResult = Network::fetchUrlFull($request['url'], true, $redirects, 10);
		$img_str = $fetchResult->getBody();

		$tempfile = tempnam(get_temppath(), 'cache');
		file_put_contents($tempfile, $img_str);
		$mime = mime_content_type($tempfile);
		unlink($tempfile);

		// If there is an error then return a blank image
		if ((substr($fetchResult->getReturnCode(), 0, 1) == '4') || (!$img_str)) {
			self::responseError($request);
			// stop.
		}

		$image = new Image($img_str, $mime);
		if (!$image->isValid()) {
			self::responseError($request);
			// stop.
		}
		
		// Store original image
		if ($direct_cache) {
			// direct cache , store under ./proxy/
			file_put_contents($basepath . '/proxy/' . ProxyUtils::proxifyUrl($request['url'], true), $image->asString());
		} elseif($cachefile !== '') {
			// cache file
			file_put_contents($cachefile, $image->asString());
		} else {
			// database
			Photo::store($image, 0, 0, $request['urlhash'], $request['url'], '', 100);
		}


		// reduce quality - if it isn't a GIF
		if ($image->getType() != 'image/gif') {
			$image->scaleDown($request['size']);
		}


		// Store scaled image
		if ($direct_cache && $request['sizetype'] != '') {
			file_put_contents($basepath . '/proxy/' . ProxyUtils::proxifyUrl($request['url'], true) . $request['sizetype'], $image->asString());
		}

		self::responseImageHttpCache($image);
		// stop.
	}


	/**
	 * @brief Build info about requested image to be proxied
	 *
	 * @return array
	 *    [
	 *      'url' => requested url,
	 *      'urlhash' => sha1 has of the url prefixed with 'pic:',
	 *      'size' => requested image size (int)
	 *      'sizetype' => requested image size (string): ':micro', ':thumb', ':small', ':medium', ':large'
	 *    ]
	 */
	private static function getRequestInfo()
	{
		$a = self::getApp();
		$url = '';
		$size = 1024;
		$sizetype = '';
		
		
		// Look for filename in the arguments
		if (($a->argc > 1) && !isset($_REQUEST['url'])) {
			if (isset($a->argv[3])) {
				$url = $a->argv[3];
			} elseif (isset($a->argv[2])) {
				$url = $a->argv[2];
			} else {
				$url = $a->argv[1];
			}

			/// @TODO: Why? And what about $url in this case?
			if (isset($a->argv[3]) && ($a->argv[3] == 'thumb')) {
				$size = 200;
			}

			// thumb, small, medium and large.
			if (substr($url, -6) == ':micro') {
				$size = 48;
				$sizetype = ':micro';
				$url = substr($url, 0, -6);
			} elseif (substr($url, -6) == ':thumb') {
				$size = 80;
				$sizetype = ':thumb';
				$url = substr($url, 0, -6);
			} elseif (substr($url, -6) == ':small') {
				$size = 300;
				$url = substr($url, 0, -6);
				$sizetype = ':small';
			} elseif (substr($url, -7) == ':medium') {
				$size = 600;
				$url = substr($url, 0, -7);
				$sizetype = ':medium';
			} elseif (substr($url, -6) == ':large') {
				$size = 1024;
				$url = substr($url, 0, -6);
				$sizetype = ':large';
			}

			$pos = strrpos($url, '=.');
			if ($pos) {
				$url = substr($url, 0, $pos + 1);
			}

			$url = str_replace(['.jpg', '.jpeg', '.gif', '.png'], ['','','',''], $url);

			$url = base64_decode(strtr($url, '-_', '+/'), true);

		} else {
			$url = defaults($_REQUEST, 'url', '');
		}
		
		return [
			'url' => $url,
			'urlhash' => 'pic:' . sha1($url),
			'size' => $size,
			'sizetype' => $sizetype,
		];
	}
	
	
	/**
	 * @brief setup ./proxy folder for direct cache
	 *
	 * @return bool  False if direct cache can't be used.
	 */
	private static function setupDirectCache()
	{
		$a = self::getApp();
		$basepath = $a->getBasePath();

		// If the cache path isn't there, try to create it
		if (!is_dir($basepath . '/proxy') && is_writable($basepath)) {
			mkdir($basepath . '/proxy');
		}

		// Checking if caching into a folder in the webroot is activated and working
		$direct_cache = (is_dir($basepath . '/proxy') && is_writable($basepath . '/proxy'));
		// we don't use direct cache if image url is passed in args and not in querystring 
		$direct_cache = $direct_cache && ($a->argc > 1) && !isset($_REQUEST['url']);
		
		return $direct_cache;
	}
	
	
	/**
	 * @brief Try to reply with image in cachefile
	 *
	 * @param array $request  Array from getRequestInfo
	 *
	 * @return string  Cache file name, empty string if cache is not enabled.
	 * 
	 * If cachefile exists, script ends here and this function will never returns
	 */
	private static function responseFromCache(&$request)
	{
		$cachefile = get_cachefile(hash('md5', $request['url']));
		if ($cachefile != '' && file_exists($cachefile)) {
			$img = new Image(file_get_contents($cachefile), mime_content_type($cachefile));
			self::responseImageHttpCache($img);
			// stop.
		}
		return $cachefile;
	}
	
	/**
	 * @brief Try to reply with image in database
	 *
	 * @param array $request  Array from getRequestInfo
	 *
	 * If the image exists in database, then script ends here and this function will never returns
	 */
	private static function responseFromDB(&$request) {
	
		$photo = Photo::getPhoto($request['urlhash']);

		if ($photo !== false) {
			$img = Photo::getImageForPhoto($photo);
			self::responseImageHttpCache($img);
			// stop.
		}
	}
	
	/**
	 * @brief Output a blank image, without cache headers, in case of errors
	 *
	 */
	private static function responseError() {
		header('Content-type: ' . $img->getType());
		echo file_get_contents('images/blank.png');
		exit();
	}
	
	/**
	 * @brief Output the image with cache headers
	 *
	 * @param Image $image
	 */
	private static function responseImageHttpCache(Image $img)
	{
		if (is_null($img) || !$img->isValid()) {
			self::responseError();
			// stop.
		}
		header('Content-type: ' . $img->getType());
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s', time()) . ' GMT');
		header('Etag: "' . md5($img->asString()) . '"');
		header('Expires: ' . gmdate('D, d M Y H:i:s', time() + (31536000)) . ' GMT');
		header('Cache-Control: max-age=31536000');
		echo $img->asString();
		exit();
	}
}
