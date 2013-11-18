<?php

/***

  Edited and modified by faucet@bitwatch.co on 2013-11-15.

  Source:
    https://github.com/scintill/php-bitcoin-signature-routines

  Author:
    https://github.com/scintill
    
  Title:
    php-bitcoin-signature-routines

  Description:
    PHP routines for verifying Bitcoin signatures. Requires PHP 5.3.2 and
  the  extension GMP 4.2.0 or better. GMP may be available in a package called
  "php5-gmp" or similar for your system.

  The main code is in verifymessage.php, with tests in test/verifymessage.php. 
  You will probably want to encapsulate the code more nicely, but since the ECC 
  library needs to be configured specially and I am not up-to-date on the latest 
  PHP packaging hotness anyway, I figured it would be best to leave that up to 
  users to do how they like best.
  
***/

// PHP 5.3.2 and GMP 4.2.0 (for larger base conversions), or higher, required

// configure the ECC lib
if(extension_loaded('gmp'))
{
  define('USE_EXT', 'GMP');
}
else
{
  die('GMP extension required.'); // It may be available in a package called "php5-gmp" or similar for your system
}

// curve definition
// http://www.secg.org/download/aid-784/sec2-v2.pdf
$secp256k1 = new CurveFp(
  '0xFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F', '0', '7');
$secp256k1_G = new Point($secp256k1,
  '0x79BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798',
  '0x483ADA7726A3C4655DA4FBFC0E1108A8FD17B448A68554199C47D08FFB10D4B8',
  '0xFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141');

// Added on 2013-11-15 by faucet@bitwatch.co
function isValidBitcoinAddress($address)
{
  $address = base58check_decode($address);
  return strlen($address) == 21 and $address[0] == "\x0";
}

// Added/modified Added on 2013-11-15 by faucet@bitwatch.co
function getVerifiedAddress($signature, $message)
{
  $derivedAddress = getVerifiedAddress160Bin($signature, $message);	
  $encoded = base58check_encode($derivedAddress);	
  return $encoded;
}

// Added/modified on 2013-11-15 by faucet@bitwatch.co
function isMessageSignatureValid($address, $signature, $message)
{
  $address = base58check_decode($address);
  if(strlen($address) != 21 || $address[0] != "\x0")
  {
    throw new InvalidArgumentException('Invalid Bitcoin address.');
  }
  $derivedAddress = getVerifiedAddress160Bin($signature, $message);
  $derivedAddress = "\x00" . $derivedAddress;
  return $address === $derivedAddress;
}

// Added/modified on 2013-11-15 by faucet@bitwatch.co
function getVerifiedAddress160Bin($signature, $message)
{
  global $secp256k1_G;
  
  $signature = base64_decode($signature, true);
  if($signature === false)
  {
    throw new InvalidArgumentException('Invalid Base64 signature.');
  }
  
  if(strlen($signature) != 65)
  {
    throw new InvalidArgumentException('Invalid signature length.');
  }

  $recoveryFlags = ord($signature[0]) - 27;
  if($recoveryFlags < 0 || $recoveryFlags > 7)
  {
    throw new InvalidArgumentException('Invalid signature type.');
  }
  $isCompressed = ($recoveryFlags & 4) != 0;
  
  // hash message, recover key
  $messageHash = hash('sha256', hash('sha256', "\x18Bitcoin Signed Message:\n" . numToVarIntString(strlen($message)).$message, true), true);
  $pubkey = recoverPubKey(bin2gmp(substr($signature, 1, 32)), bin2gmp(substr($signature, 33, 32)), bin2gmp($messageHash), $recoveryFlags, $secp256k1_G);
  if($pubkey === false)
  {
    throw new InvalidArgumentException('Unable to recover key.');
  }
  $point = $pubkey->getPoint();
  
  // see that the key we recovered is for the address given
  if(!$isCompressed)
  {
    $pubBinStr = "\x04" . str_pad(gmp2bin($point->getX()), 32, "\x00", STR_PAD_LEFT) .
                          str_pad(gmp2bin($point->getY()), 32, "\x00", STR_PAD_LEFT);
  }
  else
  {
    $pubBinStr = (isBignumEven($point->getY()) ? "\x02" : "\x03") .
                  str_pad(gmp2bin($point->getX()), 32, "\x00", STR_PAD_LEFT);
  }
  
  $derivedAddress = hash('ripemd160', hash('sha256', $pubBinStr, true), true);	
  return $derivedAddress;
}

// Added on 2013-11-15 by faucet@bitwatch.co
function base58check_encode($hash160)
{
  $hash = "\x00" . $hash160;
  $eccfull = hash('sha256', hash('sha256', $hash, true), true);
  $ecc = substr($eccfull, 0, 4);
  $preaddr = $hash.$ecc;
  $bin2hex = bchexdec(bin2hex($preaddr));
  $encoded = base58_encode($bin2hex);
  $encoded = '1'.$encoded;
  return $encoded;
}

// Added on 2013-11-15 by faucet@bitwatch.co
function base58_encode($input)
{
  $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
  $base_count = strval(strlen($alphabet));
  $encoded = '';
  while ($input >= $base_count)
  {
    $div = bcdiv($input, $base_count);
    $mod = bcmod($input, $base_count);
    $encoded = substr($alphabet, intval($mod), 1) . $encoded;
    $input = $div;
  }
  if($input >= 0)
  {
    $encoded = substr($alphabet, intval($input), 1) . $encoded;
  }
  return($encoded);
}

// Added on 2013-11-15 by faucet@bitwatch.co
function bchexdec($hex)
{
  if(strlen($hex) == 1)
  {
    return hexdec($hex);
  }
  else
  {
    $remain = substr($hex, 0, -1);
    $last = substr($hex, -1);
    return bcadd(bcmul(16, bchexdec($remain)), hexdec($last));
  }
}

function isBignumEven($bnStr)
{
  return (((int)$bnStr[strlen($bnStr)-1]) & 1) == 0;
}

// based on bitcoinjs-lib's implementation
// and SEC 1: Elliptic Curve Cryptography, section 4.1.6, "Public Key Recovery Operation".
// http://www.secg.org/download/aid-780/sec1-v2.pdf
function recoverPubKey($r, $s, $e, $recoveryFlags, $G)
{
  $isYEven = ($recoveryFlags & 1) != 0;
  $isSecondKey = ($recoveryFlags & 2) != 0;
  $curve = $G->getCurve();
  $signature = new Signature($r, $s);

  // Precalculate (p + 1) / 4 where p is the field order
  static $p_over_four; // XXX just assuming only one curve/prime will be used
  if(!$p_over_four)
  {
    $p_over_four = gmp_div(gmp_add($curve->getPrime(), 1), 4);
  }

  // 1.1 Compute x
  if(!$isSecondKey)
  {
    $x = $r;
  }
  else
  {
    $x = gmp_add($r, $G->getOrder());
  }
  
  // 1.3 Convert x to point
  $alpha = gmp_mod(gmp_add(gmp_add(gmp_pow($x, 3), gmp_mul($curve->getA(), $x)), $curve->getB()), $curve->getPrime());
  $beta = NumberTheory::modular_exp($alpha, $p_over_four, $curve->getPrime());
  
  // If beta is even, but y isn't or vice versa, then convert it,
  // otherwise we're done and y == beta.
  if(isBignumEven($beta) == $isYEven)
  {
    $y = gmp_sub($curve->getPrime(), $beta);
  }
  else
  {
    $y = $beta;
  }
  
  // 1.4 Check that nR is at infinity (implicitly done in construtor)
  $R = new Point($curve, $x, $y, $G->getOrder());
  
  $point_negate = function($p) { return new Point($p->curve, $p->x, gmp_neg($p->y), $p->order); };
  
  // 1.6.1 Compute a candidate public key Q = r^-1 (sR - eG)
  $rInv = NumberTheory::inverse_mod($r, $G->getOrder());
  $eGNeg = $point_negate(Point::mul($e, $G));
  $Q = Point::mul($rInv, Point::add(Point::mul($s, $R), $eGNeg));
  
  // 1.6.2 Test Q as a public key
  $Qk = new PublicKey($G, $Q);
  if($Qk->verifies($e, $signature))
  {
    return $Qk;
  }
  
  return false;
}

function base58check_decode($str)
{
  // strtr thanks to https://github.com/prusnak/addrgen/blob/master/php/addrgen.php
  // ltrim because leading zeroes can mess up the parsing even if you specify the base..
  $v = gmp_init(ltrim(strtr($str,
    '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz',
    '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuv'), '0'), 58);
  $v = gmp2bin($v);
  // for each leading 1, pre-pad the byte array with a 0
  for ($i = 0; $i < strlen($str); $i++)
  {
    if($str[$i] != '1')
    {
      break;
    }
    $v = "\x00" . $v;
  }
  
  $checksum = substr($v, -4);
  $v = substr($v, 0, -4);
  
  $expCheckSum = substr(hash('sha256', hash('sha256', $v, true), true), 0, 4);
  
  if($expCheckSum != $checksum)
  {
    throw new InvalidArgumentException('invalid checksum');
  }
  
  return $v;
}

function numToVarIntString($i)
{
  if($i < 0xfd)
  {
    return chr($i);
  }
  else if($i <= 0xffff)
  {
    return pack('Cv', 0xfd, $i);
  }
  else if($i <= 0xffffffff)
  {
    return pack('CV', 0xfe, $i);
  }
  else
  {
    throw new InvalidArgumentException('int too large');
  }
}

function bin2gmp($binStr)
{
  $v = gmp_init('0');

  for ($i = 0; $i < strlen($binStr); $i++)
  {
    $v = gmp_add(gmp_mul($v, 256), ord($binStr[$i]));
  }

  return $v;
}

function gmp2bin($v)
{
  $binStr = '';
  
  while (gmp_cmp($v, 0) > 0)
  {
    list($v, $r) = gmp_div_qr($v, 256);
    $binStr = chr(gmp_intval($r)) . $binStr;
  }
  
  return $binStr;
}

// Setup-stuff cribbed from index.php in the ECC repo
function __autoload($f)
{
  $base = "inc/phpecc/";
  $interfaceFile = $base . "classes/interface/" . $f . "Interface.php";
  
  if(file_exists($interfaceFile))
  {
    require_once $interfaceFile;
  }
  
  $classFile = $base . "classes/" . $f . ".php";
  if(file_exists($classFile))
  {
    require_once $classFile;
  }
  
  $utilFile = $base . "classes/util/" . $f . ".php";
  if(file_exists($utilFile))
  {
    require_once $utilFile;
  }
}

?>