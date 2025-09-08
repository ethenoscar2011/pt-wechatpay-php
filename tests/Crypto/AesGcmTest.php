<?php

namespace PtWeChatPay\Tests\Crypto;

use PHPUnit\Framework\TestCase;
use PtWeChatPay\Crypto\AesGcm;
use PtWeChatPay\Crypto\AesInterface;
use PtWeChatPay\Formatter;
use function class_implements;
use function method_exists;

class AesGcmTest extends TestCase
{
    private static $BASE64_EXPRESSION = '#^[a-zA-Z0-9\+/]+={0,2}$#';

    /**
     * Skip AES-GCM tests if OpenSSL doesn't support required algorithms
     */
    private function skipIfUnsupported()
    {
        if (version_compare(PHP_VERSION, '7.1.0', '<')) {
            // For PHP 5.6, we use custom GCM implementation, so no need to skip
            return;
        }

        if (!in_array('aes-256-gcm', openssl_get_cipher_methods())) {
            $this->markTestSkipped('AES-256-GCM is not supported by OpenSSL on this system.');
        }
    }

    public function testImplementsAesInterface()
    {
        $map = class_implements(AesGcm::class);

        $this->assertInternalType('array', $map);
        $this->assertNotEmpty($map);
        $this->assertArrayHasKey(AesInterface::class, (array)$map);
        if (method_exists($this, 'assertContainsEquals')) {
            $this->assertContainsEquals(AesInterface::class, (array)$map);
        }
    }

    public function testClassConstants()
    {
        $this->assertInternalType('string', AesGcm::ALGO_AES_256_GCM);
        $this->assertInternalType('integer', AesGcm::KEY_LENGTH_BYTE);
        $this->assertInternalType('integer', AesGcm::BLOCK_SIZE);
    }

    /**
     * @return array
     */
    public function dataProvider()
    {
        return array(
            'random key and iv' => array(
                'hello wechatpay 你好 微信支付',
                Formatter::nonce(AesGcm::KEY_LENGTH_BYTE),
                Formatter::nonce(AesGcm::BLOCK_SIZE),
                '',
            ),
            'random key, iv and aad' => array(
                'hello wechatpay 你好 微信支付',
                Formatter::nonce(AesGcm::KEY_LENGTH_BYTE),
                Formatter::nonce(AesGcm::BLOCK_SIZE),
                Formatter::nonce(AesGcm::BLOCK_SIZE),
            ),
        );
    }

    /**
     * @dataProvider dataProvider
     * @param string $plaintext
     * @param string $key
     * @param string $iv
     * @param string $aad
     */
    public function testEncrypt($plaintext, $key, $iv, $aad)
    {
        $this->skipIfUnsupported();
        $ciphertext = AesGcm::encrypt($plaintext, $key, $iv, $aad);
        $this->assertInternalType('string', $ciphertext);
        $this->assertNotEquals($plaintext, $ciphertext);

        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression(self::$BASE64_EXPRESSION, $ciphertext);
        } else {
            $this->assertRegExp(self::$BASE64_EXPRESSION, $ciphertext);
        }
    }

    /**
     * @dataProvider dataProvider
     * @param string $plaintext
     * @param string $key
     * @param string $iv
     * @param string $aad
     */
    public function testDecrypt($plaintext, $key, $iv, $aad)
    {
        $this->skipIfUnsupported();
        $ciphertext = AesGcm::encrypt($plaintext, $key, $iv, $aad);
        $this->assertInternalType('string', $ciphertext);
        $this->assertNotEquals($plaintext, $ciphertext);

        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression(self::$BASE64_EXPRESSION, $ciphertext);
        } else {
            $this->assertRegExp(self::$BASE64_EXPRESSION, $ciphertext);
        }

        $mytext = AesGcm::decrypt($ciphertext, $key, $iv, $aad);
        $this->assertInternalType('string', $mytext);
        $this->assertEquals($plaintext, $mytext);
    }
}
