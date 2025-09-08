<?php

namespace PtWeChatPay\Tests\Crypto;

use PHPUnit\Framework\TestCase;
use PtWeChatPay\Crypto\Rsa;
use UnexpectedValueException;
use function file_get_contents;
use function method_exists;
use function openssl_pkey_get_private;
use function openssl_pkey_get_public;
use function openssl_random_pseudo_bytes;
use function openssl_x509_read;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_replace;
use function substr;
use const OPENSSL_PKCS1_OAEP_PADDING;
use const OPENSSL_PKCS1_PADDING;
use const PHP_MAJOR_VERSION;

class RsaTest extends TestCase
{
    private static $BASE64_EXPRESSION = '#^[a-zA-Z0-9\+/]+={0,2}$#';

    private static $FIXTURES = __DIR__.'/../fixtures/mock.%s.%s';

    private static $EVELOPE = '#-{5}BEGIN[^-]+-{5}\r?\n(?<base64>[^-]+)\r?\n-{5}END[^-]+-{5}#';


    public function testClassConstants()
    {
        $this->assertInternalType('string', Rsa::KEY_TYPE_PRIVATE);
        $this->assertInternalType('string', Rsa::KEY_TYPE_PUBLIC);
    }

    /**
     * @param string $type
     * @param string $suffix
     */
    private function getMockContents($type, $suffix)
    {
        $file = sprintf(self::$FIXTURES, $type, $suffix);
        $pkey = file_get_contents($file);

        preg_match(self::$EVELOPE, $pkey ?: '', $matches);

        return str_replace(array("\r", "\n"), '', isset($matches['base64']) ? $matches['base64'] : '');
    }

    public function testFromPkcs8()
    {
        $thing = $this->getMockContents('pkcs8', 'key');

        $this->assertInternalType('string', $thing);
        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression(self::$BASE64_EXPRESSION, $thing);
        } else {
            $this->assertRegExp(self::$BASE64_EXPRESSION, $thing);
        }

        $pkey = Rsa::fromPkcs8($thing);

        if (8 === PHP_MAJOR_VERSION) {
            $this->assertInternalType('object', $pkey);
        } else {
            $this->assertInternalType('resource', $pkey);
        }
    }

    public function testPkcs1ToSpki()
    {
        /**
         * @var string $spki
         * @var string $pkcs1
         */
        list(, , list($spki), list($pkcs1)) = array_values($this->keyPhrasesDataProvider());

        $this->assertStringStartsWith('public.spki://', $spki);
        $this->assertStringStartsWith('public.pkcs1://', $pkcs1);
        $this->assertEquals(substr($spki, 14), Rsa::pkcs1ToSpki(substr($pkcs1, 15)));
    }

    /**
     * @return array
     */
    public function pkcs1PhrasesDataProvider()
    {
        return array(
            '`private.pkcs1://`' => array($this->getMockContents('pkcs1', 'key'), Rsa::KEY_TYPE_PRIVATE),
            '`public.pkcs1://`' => array($this->getMockContents('pkcs1', 'pem'), Rsa::KEY_TYPE_PUBLIC),
        );
    }

    /**
     * @dataProvider pkcs1PhrasesDataProvider
     *
     * @param string $thing
     */
    public function testFromPkcs1($thing, $type)
    {
        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression(self::$BASE64_EXPRESSION, $thing);
        } else {
            $this->assertRegExp(self::$BASE64_EXPRESSION, $thing);
        }

        $pkey = Rsa::fromPkcs1($thing, $type);

        if (8 === PHP_MAJOR_VERSION) {
            $this->assertInternalType('object', $pkey);
        } else {
            $this->assertInternalType('resource', $pkey);
        }
    }

    public function testFromSpki()
    {
        $thing = $this->getMockContents('spki', 'pem');

        $this->assertInternalType('string', $thing);
        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression(self::$BASE64_EXPRESSION, $thing);
        } else {
            $this->assertRegExp(self::$BASE64_EXPRESSION, $thing);
        }

        $pkey = Rsa::fromSpki($thing);

        if (8 === PHP_MAJOR_VERSION) {
            $this->assertInternalType('object', $pkey);
        } else {
            $this->assertInternalType('resource', $pkey);
        }
    }

    /**
     * @return array
     */
    public function keyPhrasesDataProvider()
    {
        return array(
            '`private.pkcs1://` string' => array(
                'private.pkcs1://'.$this->getMockContents('pkcs1', 'key'),
                Rsa::KEY_TYPE_PRIVATE,
            ),
            '`private.pkcs8://` string' => array(
                'private.pkcs8://'.$this->getMockContents('pkcs8', 'key'),
                Rsa::KEY_TYPE_PRIVATE,
            ),
            '`public.spki://` string' => array(
                'public.spki://'.$this->getMockContents('spki', 'pem'),
                Rsa::KEY_TYPE_PUBLIC,
            ),
            '`public.pkcs1://` string' => array(
                'public.pkcs1://'.$this->getMockContents('pkcs1', 'pem'),
                Rsa::KEY_TYPE_PUBLIC,
            ),
            '`file://` PKCS#1 privateKey path string' => array(
                $f = 'file://'.sprintf(self::$FIXTURES, 'pkcs1', 'key'),
                Rsa::KEY_TYPE_PRIVATE,
            ),
            'OpenSSLAsymmetricKey/resource(private)1' => array(openssl_pkey_get_private($f), Rsa::KEY_TYPE_PRIVATE),
            'PKCS#1 privateKey contents' => array($f = (string)file_get_contents($f), Rsa::KEY_TYPE_PRIVATE),
            'OpenSSLAsymmetricKey/resource(private)2' => array(openssl_pkey_get_private($f), Rsa::KEY_TYPE_PRIVATE),
            '`file://` PKCS#8 privateKey path string' => array(
                $f = 'file://'.sprintf(self::$FIXTURES, 'pkcs8', 'key'),
                Rsa::KEY_TYPE_PRIVATE,
            ),
            'OpenSSLAsymmetricKey/resource(private)3' => array(openssl_pkey_get_private($f), Rsa::KEY_TYPE_PRIVATE),
            'PKCS#8 privateKey contents' => array($f = (string)file_get_contents($f), Rsa::KEY_TYPE_PRIVATE),
            'OpenSSLAsymmetricKey/resource(private)4' => array(openssl_pkey_get_private($f), Rsa::KEY_TYPE_PRIVATE),
            '`file://` SPKI publicKey path string' => array(
                $f = 'file://'.sprintf(self::$FIXTURES, 'spki', 'pem'),
                Rsa::KEY_TYPE_PUBLIC,
            ),
            'OpenSSLAsymmetricKey/resource(public)1' => array(openssl_pkey_get_public($f), Rsa::KEY_TYPE_PUBLIC),
            'SKPI publicKey contents' => array($f = (string)file_get_contents($f), Rsa::KEY_TYPE_PUBLIC),
            'OpenSSLAsymmetricKey/resource(public)2' => array(openssl_pkey_get_public($f), Rsa::KEY_TYPE_PUBLIC),
            'pkcs1 publicKey contents' => array(
                (string)file_get_contents(sprintf(self::$FIXTURES, 'pkcs1', 'pem')),
                Rsa::KEY_TYPE_PUBLIC,
            ),
            '`file://` x509 certificate string' => array(
                $f = 'file://'.sprintf(self::$FIXTURES, 'sha256', 'crt'),
                Rsa::KEY_TYPE_PUBLIC,
            ),
            'x509 certificate contents string' => array($f = (string)file_get_contents($f), Rsa::KEY_TYPE_PUBLIC),
            'OpenSSLCertificate/resource' => array(openssl_x509_read($f), Rsa::KEY_TYPE_PUBLIC),
            '`file://` PKCS#8 encrypted privateKey' => array(
                array(
                    $f = 'file://'.sprintf(self::$FIXTURES, 'encrypted.pkcs8', 'key'),
                    $w = rtrim((string)file_get_contents(sprintf(self::$FIXTURES, 'pwd', 'txt'))),
                ),
                Rsa::KEY_TYPE_PRIVATE,
            ),
            'PKCS#8 encrypted privateKey contents' => array(
                array((string)file_get_contents($f), $w),
                Rsa::KEY_TYPE_PRIVATE,
            ),
        );
    }

    /**
     * @dataProvider keyPhrasesDataProvider
     *
     * @param mixed $thing
     */
    public function testFrom($thing, $type)
    {
        $pkey = Rsa::from($thing, $type);

        if (8 === PHP_MAJOR_VERSION) {
            $this->assertInternalType('object', $pkey);
        } else {
            $this->assertInternalType('resource', $pkey);
        }
    }

    /**
     * @return array
     */
    public function keysProvider()
    {
        /**
         * @var string $pub1
         * @var string $pub2
         * @var string $pri1
         * @var string $pri2
         */
        list(
            list($pri1), list($pri2),
            list($pub1), list($pub2),
            list($pri3), list($pri4), list($pri5), list($pri6),
            list($pri7), list($pri8), list($pri9), list($pri0),
            list($pub3), list($pub4), list($pub5), list($pub6),
            list($pub7), list($crt1), list($crt2), list($crt3)
            , list($encryptedKey1), list($encryptedKey2)
            ) = array_values($this->keyPhrasesDataProvider());

        $keys = array(
            'plaintext, `public.spki://`, `private.pkcs1://`' => array(
                openssl_random_pseudo_bytes(8),
                Rsa::fromSpki(substr($pub1, 14)),
                Rsa::fromPkcs1(substr($pri1, 16)),
            ),
            'plaintext, `public.spki://`, `private.pkcs8://`' => array(
                openssl_random_pseudo_bytes(16),
                Rsa::fromSpki(substr($pub1, 14)),
                Rsa::fromPkcs8(substr($pri2, 16)),
            ),
            'plaintext, `public.pkcs1://`, `private.pkcs1://`' => array(
                openssl_random_pseudo_bytes(24),
                Rsa::fromPkcs1(substr($pub2, 15), Rsa::KEY_TYPE_PUBLIC),
                Rsa::fromPkcs1(substr($pri1, 16)),
            ),
            'plaintext, `public.pkcs1://`, `private.pkcs8://`' => array(
                openssl_random_pseudo_bytes(32),
                Rsa::fromPkcs1(substr($pub2, 15), Rsa::KEY_TYPE_PUBLIC),
                Rsa::fromPkcs8(substr($pri2, 16)),
            ),
            'plaintext, `pkcs#1 pubkey content`, `private.pkcs1://`' => array(
                openssl_random_pseudo_bytes(40),
                Rsa::from($pub7, Rsa::KEY_TYPE_PUBLIC),
                Rsa::fromPkcs1(substr($pri1, 16)),
            ),
            'plaintext, `pkcs#1 pubkey content`, `private.pkcs8://`' => array(
                openssl_random_pseudo_bytes(48),
                Rsa::from($pub7, Rsa::KEY_TYPE_PUBLIC),
                Rsa::fromPkcs8(substr($pri2, 16)),
            ),
            'txt, `SPKI file://pubkey`, [`file://`,``] privateKey' => array(
                openssl_random_pseudo_bytes(64),
                $pub3,
                array($pri3, ''),
            ),
            'txt, `SPKI pubkey content`, [`contents`,``] privateKey' => array(
                openssl_random_pseudo_bytes(72),
                $pub5,
                array($pri5, ''),
            ),
            'str, `SPKI file://pubkey`, [`file://`privateKey, pwd]' => array(
                openssl_random_pseudo_bytes(64),
                $pub3,
                $encryptedKey1,
            ),
            'str, `SPKI pubkey content`, [`encrypted contents`,pwd]' => array(
                openssl_random_pseudo_bytes(72),
                $pub5,
                $encryptedKey2,
            ),
        );

        foreach (array($pub3, $pub4, $pub5, $pub6, $crt1, $crt2, $crt3) as $pubIndex => $pub) {
            foreach (array($pri1, $pri2, $pri3, $pri4, $pri5, $pri6, $pri7, $pri8, $pri9, $pri0) as $priIndex => $pri) {
                $keys["plaintext, publicKey{$pubIndex}, privateKey{$priIndex}"] = array(
                    openssl_random_pseudo_bytes(56),
                    Rsa::from($pub, Rsa::KEY_TYPE_PUBLIC),
                    Rsa::from($pri, Rsa::KEY_TYPE_PRIVATE),
                );
            }
        }

        return $keys;
    }

    /**
     * @dataProvider keysProvider
     * @param string $plaintext
     * @param mixed $publicKey
     */
    public function testEncrypt($plaintext, $publicKey)
    {
        $ciphertext = Rsa::encrypt($plaintext, $publicKey);
        $this->assertInternalType('string', $ciphertext);
        $this->assertNotEquals($plaintext, $ciphertext);

        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression(self::$BASE64_EXPRESSION, $ciphertext);
        } else {
            $this->assertRegExp(self::$BASE64_EXPRESSION, $ciphertext);
        }
    }

    /**
     * @dataProvider keysProvider
     * @param string $plaintext
     * @param mixed $publicKey
     * @param mixed $privateKey
     */
    public function testDecrypt($plaintext, $publicKey, $privateKey)
    {
        $ciphertext = Rsa::encrypt($plaintext, $publicKey);
        $this->assertInternalType('string', $ciphertext);
        $this->assertNotEquals($plaintext, $ciphertext);

        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression(self::$BASE64_EXPRESSION, $ciphertext);
        } else {
            $this->assertRegExp(self::$BASE64_EXPRESSION, $ciphertext);
        }

        $mytext = Rsa::decrypt($ciphertext, $privateKey);
        $this->assertInternalType('string', $mytext);
        $this->assertEquals($plaintext, $mytext);
    }

    /**
     * @return array
     */
    public function crossPaddingPhrasesProvider()
    {
        list(, , , , , , , list($privateKey), , , , , , list($publicKey)) = array_values($this->keyPhrasesDataProvider());

        return array(
            'encrypted as OPENSSL_PKCS1_OAEP_PADDING, and decrpted as OPENSSL_PKCS1_PADDING' => array(
                openssl_random_pseudo_bytes(32),
                array($publicKey, OPENSSL_PKCS1_OAEP_PADDING),
                array($privateKey, OPENSSL_PKCS1_PADDING),
                UnexpectedValueException::class,
            ),
            'encrypted as OPENSSL_PKCS1_PADDING, and decrpted as OPENSSL_PKCS1_OAEP_PADDING' => array(
                openssl_random_pseudo_bytes(32),
                array($publicKey, OPENSSL_PKCS1_PADDING),
                array($privateKey, OPENSSL_PKCS1_OAEP_PADDING),
                UnexpectedValueException::class,
            ),
            'encrypted as OPENSSL_PKCS1_OAEP_PADDING, and decrpted as OPENSSL_PKCS1_OAEP_PADDING' => array(
                openssl_random_pseudo_bytes(32),
                array($publicKey, OPENSSL_PKCS1_OAEP_PADDING),
                array($privateKey, OPENSSL_PKCS1_OAEP_PADDING),
                null,
            ),
            'encrypted as OPENSSL_PKCS1_PADDING, and decrpted as OPENSSL_PKCS1_PADDING' => array(
                openssl_random_pseudo_bytes(32),
                array($publicKey, OPENSSL_PKCS1_PADDING),
                array($privateKey, OPENSSL_PKCS1_PADDING),
                UnexpectedValueException::class,
            ),
        );
    }

    /**
     * @dataProvider crossPaddingPhrasesProvider
     * @param string $plaintext
     * @param array $publicKeyAndPaddingMode
     * @param array $privateKeyAndPaddingMode
     * @param string|null $exception
     */
    public function testCrossEncryptDecryptWithDifferentPadding(
        $plaintext,
        array $publicKeyAndPaddingMode,
        array $privateKeyAndPaddingMode,
        $exception = null
    ) {
        if ($exception) {
            $this->expectException($exception);
        }
        $ciphertext = call_user_func_array(array('PtWeChatPay\Crypto\Rsa', 'encrypt'),
            array_merge(array($plaintext), $publicKeyAndPaddingMode));
        $decrypted = call_user_func_array(array('PtWeChatPay\Crypto\Rsa', 'decrypt'),
            array_merge(array($ciphertext), $privateKeyAndPaddingMode));
        if ($exception === null) {
            $this->assertNotEmpty($ciphertext);
            $this->assertNotEmpty($decrypted);
            $this->assertEquals($plaintext, $decrypted);
        }
    }

    /**
     * @dataProvider keysProvider
     * @param string $plaintext
     * @param mixed $publicKey
     * @param mixed $privateKey
     */
    public function testSign($plaintext, $publicKey, $privateKey)
    {
        $signature = Rsa::sign($plaintext, $privateKey);

        $this->assertInternalType('string', $signature);
        $this->assertNotEquals($plaintext, $signature);

        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression(self::$BASE64_EXPRESSION, $signature);
        } else {
            $this->assertRegExp(self::$BASE64_EXPRESSION, $signature);
        }
    }

    /**
     * @dataProvider keysProvider
     * @param string $plaintext
     * @param mixed $publicKey
     * @param mixed $privateKey
     */
    public function testVerify($plaintext, $publicKey, $privateKey)
    {
        $signature = Rsa::sign($plaintext, $privateKey);

        $this->assertInternalType('string', $signature);
        $this->assertNotEquals($plaintext, $signature);

        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression(self::$BASE64_EXPRESSION, $signature);
        } else {
            $this->assertRegExp(self::$BASE64_EXPRESSION, $signature);
        }

        $this->assertTrue(Rsa::verify($plaintext, $signature, $publicKey));
    }
}
