<?php

require_once('library/ASNValue.class.php');

function DerToPem($Der, $Private=false)
{
    //Encode:
    $Der = base64_encode($Der);
    //Split lines:
    $lines = str_split($Der, 65);
    $body = implode("\n", $lines);
    //Get title:
    $title = $Private? 'RSA PRIVATE KEY' : 'PUBLIC KEY';
    //Add wrapping:
    $result = "-----BEGIN {$title}-----\n";
    $result .= $body . "\n";
    $result .= "-----END {$title}-----\n";
 
    return $result;
}

function DerToRsa($Der)
{
    //Encode:
    $Der = base64_encode($Der);
    //Split lines:
    $lines = str_split($Der, 65);
    $body = implode("\n", $lines);
    //Get title:
    $title = 'RSA PUBLIC KEY';
    //Add wrapping:
    $result = "-----BEGIN {$title}-----\n";
    $result .= $body . "\n";
    $result .= "-----END {$title}-----\n";
 
    return $result;
}




function pkcs8_encode($Modulus,$PublicExponent) {
	//Encode key sequence
	$modulus = new ASNValue(ASNValue::TAG_INTEGER);
	$modulus->SetIntBuffer($Modulus);
	$publicExponent = new ASNValue(ASNValue::TAG_INTEGER);
	$publicExponent->SetIntBuffer($PublicExponent);
	$keySequenceItems = array($modulus, $publicExponent);
	$keySequence = new ASNValue(ASNValue::TAG_SEQUENCE);
	$keySequence->SetSequence($keySequenceItems);
	//Encode bit string
	$bitStringValue = $keySequence->Encode();
	$bitStringValue = chr(0x00) . $bitStringValue; //Add unused bits byte
	$bitString = new ASNValue(ASNValue::TAG_BITSTRING);
	$bitString->Value = $bitStringValue;
	//Encode body
	$bodyValue = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00" . $bitString->Encode();
	$body = new ASNValue(ASNValue::TAG_SEQUENCE);
	$body->Value = $bodyValue;
	//Get DER encoded public key:
	$PublicDER = $body->Encode();
	return $PublicDER;
}


function pkcs1_encode($Modulus,$PublicExponent) {
	//Encode key sequence
	$modulus = new ASNValue(ASNValue::TAG_INTEGER);
	$modulus->SetIntBuffer($Modulus);
	$publicExponent = new ASNValue(ASNValue::TAG_INTEGER);
	$publicExponent->SetIntBuffer($PublicExponent);
	$keySequenceItems = array($modulus, $publicExponent);
	$keySequence = new ASNValue(ASNValue::TAG_SEQUENCE);
	$keySequence->SetSequence($keySequenceItems);
	//Encode bit string
	$bitStringValue = $keySequence->Encode();
	return $bitStringValue;

//	$bitStringValue = chr(0x00) . $bitStringValue; //Add unused bits byte
//	$bitString = new ASNValue(ASNValue::TAG_BITSTRING);
//	$bitString->Value = $bitStringValue;
	//Encode body
//	$bodyValue = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00" . $bitString->Encode();
//	$body = new ASNValue(ASNValue::TAG_SEQUENCE);
//	$body->Value = $bodyValue;
	//Get DER encoded public key:
//	$PublicDER = $body->Encode();
//	return $PublicDER;
}


function metopem($m,$e) {
	$der = pkcs8_encode($m,$e);
	$key = DerToPem($der,false);
	return $key;
}	


function pubrsatome($key,&$m,&$e) {
	require_once('library/asn1.php');
	require_once('include/salmon.php');

	$lines = explode("\n",$key);
	unset($lines[0]);
	unset($lines[count($lines)]);
	$x = base64_decode(implode('',$lines));

	$r = ASN_BASE::parseASNString($x);

//	print_r($r);

	$m = base64url_decode($r[0]->asnData[0]->asnData);
	$e = base64url_decode($r[0]->asnData[1]->asnData);


}


function rsatopem($key) {
	pubrsatome($key,$m,$e);
	return(metopem($m,$e));
}

function pemtorsa($key) {
	pemtome($key,$m,$e);
	return(metorsa($m,$e));
}

function pemtome($key,&$m,&$e) {
	require_once('include/salmon.php');
	$lines = explode("\n",$key);
	unset($lines[0]);
	unset($lines[count($lines)]);
	$x = base64_decode(implode('',$lines));

	$r = ASN_BASE::parseASNString($x);

	$m = base64url_decode($r[0]->asnData[1]->asnData[0]->asnData[0]->asnData);
	$e = base64url_decode($r[0]->asnData[1]->asnData[0]->asnData[1]->asnData);
}

function metorsa($m,$e) {
	$der = pkcs1_encode($m,$e);
	$key = DerToRsa($der);
	return $key;
}	

