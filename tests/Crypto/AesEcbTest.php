<?php

namespace PtWeChatPay\Tests\Crypto;

use PHPUnit\Framework\TestCase;
use PtWeChatPay\Crypto\AesEcb;
use PtWeChatPay\Crypto\AesInterface;
use PtWeChatPay\Formatter;
use function class_implements;
use function is_null;
use function method_exists;

class AesEcbTest extends TestCase
{
    private static $BASE64_EXPRESSION = '#^[a-zA-Z0-9\+/]+={0,2}$#';

    public function testImplementsAesInterface()
    {
        $map = class_implements(AesEcb::class);

        $this->assertInternalType('array', $map);
        $this->assertNotEmpty($map);
        $this->assertArrayHasKey(AesInterface::class, $map);
        if (method_exists($this, 'assertContainsEquals')) {
            $this->assertContainsEquals(AesInterface::class, $map);
        }
    }

    public function testClassConstants()
    {
        $this->assertInternalType('string', AesEcb::ALGO_AES_256_ECB);
        $this->assertInternalType('integer', AesEcb::KEY_LENGTH_BYTE);
    }

    /**
     * @return array
     */
    public function phrasesDataProvider()
    {
        return array(
            'fixed plaintext and key' => array(
                'hello',
                '0123456789abcdef0123456789abcdef',
                '',
                'pZwJZBLuy3mDACEQT4YTBw==',
            ),
            'random key' => array(
                'hello wechatpay 你好 微信支付',
                Formatter::nonce(AesEcb::KEY_LENGTH_BYTE),
                '',
                null,
            ),
            'empty text with random key' => array(
                '',
                Formatter::nonce(AesEcb::KEY_LENGTH_BYTE),
                '',
                null,
            ),
        );
    }

    /**
     * @dataProvider phrasesDataProvider
     * @param string $plaintext
     * @param string $key
     * @param string $iv
     * @param string|null $excepted
     */
    public function testEncrypt($plaintext, $key, $iv, $excepted = null)
    {
        $ciphertext = AesEcb::encrypt($plaintext, $key, $iv);
        $this->assertInternalType('string', $ciphertext);
        $this->assertNotEmpty($ciphertext);

        if (!is_null($excepted)) {
            $this->assertEquals($ciphertext, $excepted);
        }

        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression(self::$BASE64_EXPRESSION, $ciphertext);
        } else {
            $this->assertRegExp(self::$BASE64_EXPRESSION, $ciphertext);
        }
    }

    /**
     * @dataProvider phrasesDataProvider
     * @param string $plaintext
     * @param string $key
     * @param string $iv
     * @param string|null $ciphertext
     */
    public function testDecrypt($plaintext, $key, $iv, $ciphertext = null)
    {
        if (is_null($ciphertext)) {
            $ciphertext = AesEcb::encrypt($plaintext, $key, $iv);
        }

        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression(self::$BASE64_EXPRESSION, $ciphertext);
        } else {
            $this->assertRegExp(self::$BASE64_EXPRESSION, $ciphertext);
        }

        $this->assertInternalType('string', $ciphertext);
        $this->assertNotEmpty($ciphertext);
        $this->assertNotEquals($plaintext, $ciphertext);

        $excepted = AesEcb::decrypt($ciphertext, $key, $iv);

        $this->assertInternalType('string', $excepted);
        $this->assertEquals($plaintext, $excepted);
    }
}
