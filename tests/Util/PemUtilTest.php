<?php

namespace PtWeChatPay\Tests\Util;

use PHPUnit\Framework\TestCase;
use PtWeChatPay\Util\PemUtil;
use function file_get_contents;
use function openssl_x509_parse;
use function sprintf;
use const PHP_MAJOR_VERSION;

class PemUtilTest extends TestCase
{
    private static $FIXTURES = __DIR__.'/../fixtures/mock.%s.%s';

    private static $SUBJECT_CN = 'test.com';
    private static $SUBJECT_O = 'Test';
    private static $SUBJECT_ST = 'Beijing';
    private static $SUBJECT_C = 'CN';
    private static $SUBJECT_L = 'Beijing';
    private static $SUBJECT_OU = 'Test';

    /** @var array */
    private static $certSubject;

    /** @var array|null */
    private static $environment;

    public static function setUpBeforeClass()
    {
        $certFile = sprintf(self::$FIXTURES, 'sha256', 'crt');
        $privFile = sprintf(self::$FIXTURES, 'pkcs8', 'key');
        $certString = (string)file_get_contents($certFile);
        $privString = (string)file_get_contents($privFile);

        // Get the actual serial number from the certificate based on PHP version
        $cert = openssl_x509_read($certString);
        $info = openssl_x509_parse($cert);
        if (isset($info['serialNumberHex'])) {
            // PHP 7+ uses serialNumberHex directly
            $serial = strtoupper($info['serialNumberHex']);
        } else {
            // PHP 5.6 uses serialNumber (decimal) converted to hex
            $serial = strtoupper(dechex($info['serialNumber']));
        }

        self::$certSubject = array(
            'commonName' => self::$SUBJECT_CN,
            'organizationName' => self::$SUBJECT_O,
            'stateOrProvinceName' => self::$SUBJECT_ST,
            'countryName' => self::$SUBJECT_C,
            'localityName' => self::$SUBJECT_L,
            'organizationalUnitName' => self::$SUBJECT_OU,
        );

        self::$environment = array($serial, $certFile, $certString, $privFile, $privString, 'file://'.$certFile);
    }

    public static function tearDownAfterClass()
    {
        self::$environment = null;
    }

    public function testLoadCertificate()
    {
        list(, $certFile) = self::$environment ?: array('', '');
        $cert = PemUtil::loadCertificate($certFile);
        if (8 === PHP_MAJOR_VERSION) {
            $this->assertInternalType('object', $cert);
        } else {
            $this->assertInternalType('resource', $cert);
        }

        /** @var mixed $cert */
        $parsed = openssl_x509_parse($cert, false) ?: array();
        $subject = $parsed['subject'];
        $issuer = $parsed['issuer'];
        $this->assertEquals(self::$certSubject, $subject);
        $this->assertEquals(self::$certSubject, $issuer);
    }

    public function testLoadCertificateFromString()
    {
        list(, , $certString) = self::$environment ?: array('', '', '');
        $cert = PemUtil::loadCertificateFromString($certString);
        if (8 === PHP_MAJOR_VERSION) {
            $this->assertInternalType('object', $cert);
        } else {
            $this->assertInternalType('resource', $cert);
        }

        /** @var mixed $cert */
        $parsed = openssl_x509_parse($cert, false) ?: array();
        $subject = $parsed['subject'];
        $issuer = $parsed['issuer'];
        $this->assertEquals(self::$certSubject, $subject);
        $this->assertEquals(self::$certSubject, $issuer);
    }

    public function testLoadPrivateKey()
    {
        list(, , , $privateKeyFile) = self::$environment ?: array('', '', '', '');
        $privateKey = PemUtil::loadPrivateKey($privateKeyFile);
        if (8 === PHP_MAJOR_VERSION) {
            $this->assertInternalType('object', $privateKey);
        } else {
            $this->assertInternalType('resource', $privateKey);
        }
    }

    public function testLoadPrivateKeyFromString()
    {
        list(, , , , $privateKeyString) = self::$environment ?: array('', '', '', '', '');
        $privateKey = PemUtil::loadPrivateKeyFromString($privateKeyString);
        if (8 === PHP_MAJOR_VERSION) {
            $this->assertInternalType('object', $privateKey);
        } else {
            $this->assertInternalType('resource', $privateKey);
        }
    }

    public function testParseCertificateSerialNo()
    {
        list($serialNo, $certFile, $certString, , , $certFileProtocolString) = self::$environment ?: array(
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        );
        $serialNoFromPemUtilFile = PemUtil::parseCertificateSerialNo(PemUtil::loadCertificate($certFile));
        $serialNoFromPemUtilString = PemUtil::parseCertificateSerialNo(PemUtil::loadCertificateFromString($certString));
        $serialNoFromCertString = PemUtil::parseCertificateSerialNo($certString);
        $serialNoFromCertFileProtocolString = PemUtil::parseCertificateSerialNo($certFileProtocolString);

        // The serial number format differs between PHP versions:
        // PHP 5.6: uses serialNumber (decimal) converted to hex
        // PHP 7+: uses serialNumberHex directly
        $expectedSerial = $serialNo;

        $this->assertEquals($expectedSerial, $serialNoFromPemUtilFile);
        $this->assertEquals($expectedSerial, $serialNoFromPemUtilString);
        $this->assertEquals($expectedSerial, $serialNoFromCertString);
        $this->assertEquals($expectedSerial, $serialNoFromCertFileProtocolString);
    }
}
