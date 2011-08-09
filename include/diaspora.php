<?php

require_once('include/certfns.php');

function receive_return($val) {

	if($val >= 400)
		$err = 'Error';
	if($val >= 200 && $val < 300)
		$err = 'OK';

	logger('mod-diaspora returns ' . $val);	
	header($_SERVER["SERVER_PROTOCOL"] . ' ' . $val . ' ' . $err);
	killme();

}


function get_diaspora_key($uri) {
	$key = '';

	logger('Fetching diaspora key for: ' . $uri);

	$arr = lrdd($uri);

	if(is_array($arr)) {
		foreach($arr as $a) {
			if($a['@attributes']['rel'] === 'diaspora-public-key') {
				$key = base64_decode($a['@attributes']['href']);
			}
		}
	}
	else {
		return '';
	}

	if($key)
		return rsatopem($key);
	return '';
}


function diaspora_base_message($type,$data) {

	$tpl = get_markup_template('diaspora_' . $type . '.tpl');
	if(! $tpl) 
		return '';
	return replace_macros($tpl,$data);

}


function diaspora_msg_build($msg,$user,$contact,$prvkey,$pubkey) {
	$a = get_app();

	$inner_aes_key = random_string(32);
	$b_inner_aes_key = base64_encode($inner_aes_key);
	$inner_iv = random_string(32);
	$b_inner_iv = base64_encode($inner_iv);

	$outer_aes_key = random_string(32);
	$b_outer_aes_key = base64_encode($outer_aes_key);
	$outer_iv = random_string(32);
	$b_outer_iv = base64_encode($outer_iv);
	
	$handle = 'acct:' . $user['nickname'] . '@' . substr($a->get_baseurl(), strpos($a->get_baseurl(),'://') + 3);

	$padded_data = pkcs5_pad($msg,16);
	$inner_encrypted = mcrypt_encrypt(MCRYPT_RIJNDAEL_128, $inner_aes_key, $padded_data, MCRYPT_MODE_CBC, $inner_iv);

	$b64_data = base64_encode($inner_encrypted);
echo "inner: $b64_data";

	$b64url_data = base64url_encode($b64_data);
	$b64url_stripped = str_replace(array("\n","\r"," ","\t"),array('','','',''),$b64url_data);
    $lines = str_split($b64url_stripped,60);
    $data = implode("\n",$lines);
	$data = $data . (($data[-1] != "\n") ? "\n" : '') ;
	$type = 'application/atom+xml';
	$encoding = 'base64url';
	$alg = 'RSA-SHA256';

	$signable_data = $data  . '.' . base64url_encode($type) . "\n" . '.' 
		. base64url_encode($encoding) . "\n" . '.' . base64url_encode($alg) . "\n";

	$signature = '';
	$result = openssl_sign($signable_data,$signature,$prvkey,'SHA256');

	$sig = base64url_encode($signature);

$decrypted_header = <<< EOT
<decrypted_header>
  <iv>$b_inner_iv</iv>
  <aes_key>$b_inner_aes_key</aes_key>
  <author>
    <name>{$contact['name']}</name>
    <uri>$handle</uri>
  </author>
</decrypted_header>
EOT;

	$decrypted_header = pkcs5_pad($decrypted_header,16);
logger("decrypted_header: $decrypted_header");
	$ciphertext = mcrypt_encrypt(MCRYPT_RIJNDAEL_128, $outer_aes_key, $decrypted_header, MCRYPT_MODE_CBC, $outer_iv);

logger( "encrypted_ciphertext: " . base64_encode($ciphertext));
	logger("encrypt_outer_key: $b_outer_aes_key");
	logger("ecnrypt_outer_iv: $b_outer_iv");

	$outer_json = json_encode(array('iv' => $b_outer_iv,'key' => $b_outer_aes_key));
	$encrypted_outer_key_bundle = '';
	openssl_public_encrypt($outer_json,$encrypted_outer_key_bundle,$pubkey);
	
	$b64_encrypted_outer_key_bundle = base64_encode($encrypted_outer_key_bundle);
	$encrypted_header_json_object = json_encode(array('aes_key' => base64_encode($encrypted_outer_key_bundle), 
		'ciphertext' => base64_encode($ciphertext)));
	$encrypted_header = '<encrypted_header>' . base64_encode($encrypted_header_json_object) . '</encrypted_header>';

$magic_env = <<< EOT
<?xml version='1.0' encoding='UTF-8'?>
<entry xmlns='http://www.w3.org/2005/Atom'>
  $encrypted_header
  <me:env xmlns:me="http://salmon-protocol.org/ns/magic-env">
    <me:encoding>base64url</me:encoding>
    <me:alg>RSA-SHA256</me:alg>
    <me:data type="application/atom+xml">$data</me:data>
    <me:sig>$sig</me:sig>
  </me:env>
</entry>
EOT;

	return $magic_env;

}


function diaspora_decode($importer,$xml) {
	$basedom = parse_xml_string($xml);

	if($basedom)
		logger('parsed dom');

	$atom = $basedom->children(NAMESPACE_ATOM1);

	logger('atom: ' . count($atom));
	$encrypted_header = json_decode(base64_decode($atom->encrypted_header));

	print_r($encrypted_header);
	
	$encrypted_aes_key_bundle = base64_decode($encrypted_header->aes_key);
	$ciphertext = base64_decode($encrypted_header->ciphertext);

	logger('encrypted_aes: ' . print_r($encrypted_aes_key_bundle,true));
	logger('ciphertext: ' . print_r($ciphertext,true));

	$outer_key_bundle = '';
	openssl_private_decrypt($encrypted_aes_key_bundle,$outer_key_bundle,$importer['prvkey']);

	logger('outer_bundle: ' . print_r($outer_key_bundle,true));

	$j_outer_key_bundle = json_decode($outer_key_bundle);

	$outer_iv = base64_decode($j_outer_key_bundle->iv);
	$outer_key = base64_decode($j_outer_key_bundle->key);

	logger("outer_iv: $outer_iv");
	logger("outer_key: $outer_key");
	logger("ciphertext: " . base64_encode($ciphertext));

	$decrypted = mcrypt_decrypt(MCRYPT_RIJNDAEL_128, $outer_key, $ciphertext, MCRYPT_MODE_CBC, $outer_iv);

	$decrypted = pkcs5_unpad($decrypted);

	logger('decrypted: ' . print_r($decrypted,true));

	/**
	 * $decrypted now contains something like
	 *
	 *  <decrypted_header>
	 *     <iv>8e+G2+ET8l5BPuW0sVTnQw==</iv>
	 *     <aes_key>UvSMb4puPeB14STkcDWq+4QE302Edu15oaprAQSkLKU=</aes_key>
	 *     <author>
	 *       <name>Ryan Hughes</name>
	 *       <uri>acct:galaxor@diaspora.pirateship.org</uri>
	 *     </author>
	 *  </decrypted_header>
	 */

	$idom = parse_xml_string($decrypted,false);

	$inner_iv = base64_decode($idom->iv);
	$inner_aes_key = base64_decode($idom->aes_key);
logger('idom: ' . print_r($idom,true));

	$author_link = str_replace('acct:','',$idom->author->uri);

	logger('inner_iv: ' . $inner_iv);

	$dom = $basedom->children(NAMESPACE_SALMON_ME);

	if($dom)
		logger('have dom');

	logger('dom: ' . count($dom));
	// figure out where in the DOM tree our data is hiding

	if($dom->provenance->data)
		$base = $dom->provenance;
	elseif($dom->env->data)
		$base = $dom->env;
	elseif($dom->data)
		$base = $dom;
	
	if(! $base) {
		logger('mod-diaspora: unable to locate salmon data in xml ');
		dt_return(400);
	}


	// Stash the signature away for now. We have to find their key or it won't be good for anything.
	$signature = base64url_decode($base->sig);

	logger('signature: ' . bin2hex($signature));

	// unpack the  data

	// strip whitespace so our data element will return to one big base64 blob
	$data = str_replace(array(" ","\t","\r","\n"),array("","","",""),$base->data);
	// Add back the 60 char linefeeds
    $lines = str_split($data,60);
    $data = implode("\n",$lines);


	// stash away some other stuff for later

	$type = $base->data[0]->attributes()->type[0];
	$keyhash = $base->sig[0]->attributes()->keyhash[0];
	$encoding = $base->encoding;
	$alg = $base->alg;

	$signed_data = $data  . (($data[-1] != "\n") ? "\n" : '') . '.' . base64url_encode($type) . "\n" . '.' . base64url_encode($encoding) . "\n" . '.' . base64url_encode($alg) . "\n";

	logger('signed data: ' . $signed_data);

	// decode the data
	$data = base64url_decode($data);

	// Now pull out the inner encrypted blob

	$inner_encrypted = base64_decode($data);

	$inner_decrypted = 
	$inner_decrypted = mcrypt_decrypt(MCRYPT_RIJNDAEL_128, $inner_aes_key, $inner_encrypted, MCRYPT_MODE_CBC, $inner_iv);

	$inner_decrypted = pkcs5_unpad($inner_decrypted);

	logger('inner_decrypted: ' . $inner_decrypted);



	if(! $author_link) {
		logger('mod-diaspora: Could not retrieve author URI.');
		receive_return(400);
	}

	// Once we have the author URI, go to the web and try to find their public key
	// *** or look it up locally ***

	logger('mod-diaspora: Fetching key for ' . $author_link );

	// Get diaspora public key (pkcs#1) and convert to pkcs#8
 	$key = get_diaspora_key($author_link);

	if(! $key) {
		logger('mod-salmon: Could not retrieve author key.');
		receive_return(400);
	}

	$verify = false;

	if (version_compare(PHP_VERSION, '5.3.0', '>=')) {
    	$verify = openssl_verify($signed_data,$signature,$key,'sha256');
	}
	else {
		// fallback sha256 verify for PHP < 5.3
		$rawsig = '';
		$hash = hash('sha256',$signed_data,true);
		openssl_public_decrypt($signature,$rawsig,$key);
		$verify = (($rawsig && substr($rawsig,-32) === $hash) ? true : false);
	}

	if(! $verify) {
		logger('mod-diaspora: Message did not verify. Discarding.');
		receive_return(400);
	}

	logger('mod-diaspora: Message verified.');

	return $inner_decrypted;

}




