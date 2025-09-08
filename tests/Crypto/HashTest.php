<?php

namespace PtWeChatPay\Tests\Crypto;

use PHPUnit\Framework\TestCase;
use PtWeChatPay\Crypto\Hash;
use PtWeChatPay\Formatter;
use function hash_hmac;
use function is_null;
use function md5;
use function method_exists;
use function openssl_random_pseudo_bytes;
use function strlen;

class HashTest extends TestCase
{
    public function testClassConstants()
    {
        $this->assertInternalType('string', Hash::ALGO_MD5);
        $this->assertInternalType('string', Hash::ALGO_HMAC_SHA256);
    }

    /**
     * @return array
     */
    public function md5DataProvider()
    {
        return array(
            'without key equals to normal md5' => array(
                $txt = Formatter::nonce(30),
                '',
                '',
                md5($txt),
                'assertEquals',
                32,
            ),
            'input agency, but without key equals to normal md5' => array(
                $txt = Formatter::nonce(60),
                '',
                true,
                md5($txt),
                'assertEquals',
                32,
            ),
            'random key without agency' => array(
                $txt = Formatter::nonce(90),
                Formatter::nonce(),
                '',
                md5($txt),
                'assertNotEquals',
                32,
            ),
            'random key with agency:true' => array(
                $txt = Formatter::nonce(200),
                Formatter::nonce(),
                true,
                md5($txt),
                'assertNotEquals',
                32,
            ),
        );
    }

    /**
     * @dataProvider md5DataProvider
     * @param string $thing
     * @param string $key
     * @param string $agency
     * @param string $excepted
     * @param string $action
     * @param int $length
     */
    public function testMd5($thing, $key, $agency, $excepted, $action, $length)
    {
        $digest = Hash::md5($thing, $key, $agency);

        $this->assertInternalType('string', $digest);
        $this->assertNotEmpty($digest);
        $this->assertNotEquals($thing, $digest);
        $this->assertEquals(strlen($digest), $length);
        $this->{$action}($digest, $excepted);
    }

    /**
     * @return array
     */
    public function hmacDataProvider()
    {
        return array(
            'not equals to normal hash_hmac:md5' => array(
                $txt = Formatter::nonce(900),
                $key = Formatter::nonce(),
                $algo = 'md5',
                hash_hmac($algo, $txt, $key),
                'assertNotEquals',
                32,
            ),
            'not equals to normal hash_hmac:sha256' => array(
                $txt = Formatter::nonce(600),
                $key = Formatter::nonce(),
                $algo = 'sha256',
                hash_hmac($algo, $txt, $key),
                'assertNotEquals',
                64,
            ),
            'not equals to normal hash_hmac:sha384' => array(
                $txt = Formatter::nonce(300),
                $key = Formatter::nonce(),
                $algo = 'sha384',
                hash_hmac($algo, $txt, $key),
                'assertNotEquals',
                96,
            ),
        );
    }

    /**
     * @dataProvider hmacDataProvider
     * @param string $thing
     * @param string $key
     * @param string $algorithm
     * @param string $excepted
     * @param string $action
     * @param int $length
     */
    public function testHmac($thing, $key, $algorithm, $excepted, $action, $length)
    {
        $digest = Hash::hmac($thing, $key, $algorithm);

        $this->assertInternalType('string', $digest);
        $this->assertNotEmpty($digest);
        $this->assertNotEquals($thing, $digest);
        $this->assertEquals(strlen($digest), $length);
        $this->{$action}($digest, $excepted);
    }

    /**
     * @return array
     */
    public function equalsDataProvider()
    {
        return array(
            'empty string equals to empty string' => array('', '', true),
            'empty string not equals to null' => array('', null, false),
            'random_bytes(16) not equals to null' => array(openssl_random_pseudo_bytes(16), null, false),
        );
    }

    /**
     * @dataProvider equalsDataProvider
     *
     * @param string $known
     * @param string|null $user
     * @param bool $excepted
     */
    public function testEquals($known, $user = null, $excepted = false)
    {
        $result = Hash::equals($known, $user);
        $this->assertInternalType('boolean', $result);
        $this->assertThat($result, $excepted ? $this->isTrue() : $this->isFalse());
    }

    /**
     * @return array
     */
    public function signDataProvider()
    {
        return array(
            'not equals to normal Hash::md5' => array(
                $txt = Formatter::nonce(900),
                $key = Formatter::nonce(),
                Hash::ALGO_MD5,
                Hash::md5($txt, $key),
                'assertNotEquals',
                32,
            ),
            'not equals to normal Hash::hmac' => array(
                $txt = Formatter::nonce(600),
                $key = Formatter::nonce(),
                Hash::ALGO_HMAC_SHA256,
                Hash::hmac($txt, $key),
                'assertNotEquals',
                64,
            ),
            'not support algo sha256' => array(
                $txt = Formatter::nonce(300),
                $key = Formatter::nonce(),
                'sha256',
                '',
                'assertNull',
                null,
            ),
        );
    }

    /**
     * @dataProvider signDataProvider
     * @param string $type
     * @param string $thing
     * @param string $key
     * @param string $excepted
     * @param string $action
     * @param int|null $length
     */
    public function testSign($thing, $key, $type, $excepted, $action, $length = null)
    {
        $digest = Hash::sign($type, $thing, $key);
        if (is_null($length)) {
            $this->{$action}($digest);
        } else {
            $this->assertNotNull($digest);
            $this->assertInternalType('string', $digest);
            $this->assertNotEmpty($digest);
            $this->assertNotEquals($thing, $digest);
            $this->assertEquals(strlen($digest), $length);
            $this->{$action}($digest, $excepted);
            if (method_exists($this, 'assertMatchesRegularExpression')) {
                $this->assertMatchesRegularExpression('#[A-Z]+#', $digest);
            } else {
                $this->assertRegExp('#[A-Z]+#', $digest);
            }
        }
    }
}
