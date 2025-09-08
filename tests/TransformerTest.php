<?php

namespace PtWeChatPay\Tests;

use PHPUnit\Framework\TestCase;
use PtWeChatPay\Transformer;
use function array_map;
use function error_clear_last;
use function error_get_last;
use function file_get_contents;
use function is_string;
use function json_encode;
use function method_exists;
use const DIRECTORY_SEPARATOR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

class TransformerTest extends TestCase
{
    /**
     * @return array
     */
    public function xmlToArrayDataProvider()
    {
        $baseDir = __DIR__.DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR;

        return array(
            'sendredpack.sample.xml' => array(
                file_get_contents($baseDir.'sendredpack.sample.xml') ?: '',
                array(
                    'sign',
                    'mch_billno',
                    'mch_id',
                    'wxappid',
                    'send_name',
                    're_openid',
                    'total_amount',
                    'total_num',
                    'wishing',
                    'client_ip',
                    'act_name',
                    'remark',
                    'scene_id',
                    'nonce_str',
                    'risk_info',
                ),
            ),
            'paysuccess.notification.sample.xml' => array(
                file_get_contents($baseDir.'paysuccess.notification.sample.xml') ?: '',
                array(
                    'appid',
                    'attach',
                    'bank_type',
                    'fee_type',
                    'is_subscribe',
                    'mch_id',
                    'nonce_str',
                    'openid',
                    'out_trade_no',
                    'result_code',
                    'return_code',
                    'sign',
                    'time_end',
                    'total_fee',
                    'coupon_fee',
                    'coupon_count',
                    'coupon_type',
                    'coupon_id',
                    'trade_type',
                    'transaction_id',
                ),
            ),
            'unifiedorder.sample.xml' => array(
                file_get_contents($baseDir.'unifiedorder.sample.xml') ?: '',
                array(
                    'appid',
                    'attach',
                    'body',
                    'mch_id',
                    'detail',
                    'nonce_str',
                    'notify_url',
                    'openid',
                    'out_trade_no',
                    'spbill_create_ip',
                    'total_fee',
                    'trade_type',
                    'sign',
                ),
            ),
            'refund.notification.req_info.sample.xml' => array(
                file_get_contents($baseDir.'refund.notification.req_info.sample.xml') ?: '',
                array(
                    'out_refund_no',
                    'out_trade_no',
                    'refund_account',
                    'refund_fee',
                    'refund_id',
                    'refund_recv_accout',
                    'refund_request_source',
                    'refund_status',
                    'settlement_refund_fee',
                    'settlement_total_fee',
                    'success_time',
                    'total_fee',
                    'transaction_id',
                ),
            ),
            'getpublickey.response.sample.xml' => array(
                file_get_contents($baseDir.'getpublickey.response.sample.xml') ?: '',
                array(
                    'return_code',
                    'return_msg',
                    'result_code',
                    'mch_id',
                    'pub_key',
                ),
            ),
        );
    }

    /**
     * @dataProvider xmlToArrayDataProvider
     * @param string $xmlString
     * @param array $keys
     */
    public function testToArray($xmlString, array $keys)
    {
        /** @var array $array */
        $array = Transformer::toArray($xmlString);

        $this->assertInternalType('array', $array);
        $this->assertNotEmpty($array);

        array_map(function ($key) use ($array) {
            $this->assertArrayHasKey($key, $array);
            $this->assertInternalType('string', $array[$key]);
            $this->assertNotContains('<![CDATA[', $array[$key]);
            $this->assertNotContains(']]>', $array[$key]);
        }, $keys);
    }

    /**
     * @return array
     */
    public function xmlToArrayBadPhrasesDataProvider()
    {
        $baseDir = __DIR__.DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR;

        return array(
            $f = 'fragment_injection.sample.xml' => array((string)file_get_contents($baseDir.$f), null),
            $f = 'invalid.xxe_injection.sample.xml' => array((string)file_get_contents($baseDir.$f), null),
            $f = 'invalid.bad_entity.sample.xml' => array(
                (string)file_get_contents($baseDir.$f),
                '#^Parsing the \$xml failed with the last error#',
            ),
            $f = 'invalid.normal_404.sample.html' => array(
                (string)file_get_contents($baseDir.$f),
                '#^Parsing the \$xml failed with the last error#',
            ),
        );
    }

    /**
     * @dataProvider xmlToArrayBadPhrasesDataProvider
     * @param string $xmlString
     * @param string|null $pattern
     */
    public function testToArrayBadPhrases($xmlString, $pattern = null)
    {
        error_clear_last();
        $array = Transformer::toArray($xmlString);
        $this->assertInternalType('array', $array);
        if (is_string($pattern)) {
            $this->assertEmpty($array);
            /** @var array $err */
            $err = error_get_last();
            if (method_exists($this, 'assertMatchesRegularExpression')) {
                $this->assertMatchesRegularExpression($pattern, $err['message']);
            } else {
                $this->assertRegExp($pattern, $err['message']);
            }
        } else {
            $this->assertNotEmpty($array);
        }
    }

    /**
     * @return array
     */
    public function arrayToXmlDataProvider()
    {
        $jsonModifier = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        return array(
            'normal 1-depth array with extra default options' => array(
                array(
                    'appid' => 'wx2421b1c4370ec43b',
                    'body' => 'dummybot',
                    'mch_id' => '10000100',
                    'detail' => json_encode(array(array('goods_detail' => '华为手机', 'url' => 'https://huawei.com')),
                        $jsonModifier) ?: '',
                ),
                true,
                false,
                'xml',
                'item',
            ),
            'normal 1-depth array with headless=false and indent=true' => array(
                array(
                    'appid' => 'wx2421b1c4370ec43b',
                    'body' => 'dummybot',
                    'mch_id' => '10000100',
                    'detail' => json_encode(array(array('goods_detail' => '华为手机', 'url' => 'https://huawei.com')),
                        $jsonModifier) ?: '',
                ),
                false,
                true,
                'xml',
                'item',
            ),
            '2-depth array with extra default options' => array(
                array(
                    'appid' => 'wx2421b1c4370ec43b',
                    'body' => 'dummybot',
                    'mch_id' => '10000100',
                    'detail' => array(array('goods_detail' => '华为手机', 'url' => 'https://huawei.com')),
                ),
                true,
                false,
                'xml',
                'item',
            ),
            '2-depth array with with headless=false, indent=true and root=qqpay' => array(
                array(
                    'appid' => 'wx2421b1c4370ec43b',
                    'body' => 'dummybot',
                    'mch_id' => '10000100',
                    'detail' => array(array('goods_detail' => '华为手机', 'url' => 'https://huawei.com')),
                ),
                false,
                true,
                'qqpay',
                'item',
            ),
            'transform the Stringable values' => array(
                array(
                    'appid' => 'wx2421b1c4370ec43b',
                    'body' => 'dummybot',
                    'mch_id' => '10000100',
                    'finished' => true,
                    'amount' => 100,
                    'recevier' => new StringableTestClass(),
                ),
                true,
                false,
                'xml',
                'item',
            ),
        );
    }

    /**
     * @dataProvider arrayToXmlDataProvider
     * @param array $data
     * @param bool $headless
     * @param bool $indent
     * @param string $root
     * @param string $item
     */
    public function testToXml(array $data, $headless, $indent, $root, $item)
    {
        $xml = Transformer::toXml($data, $headless, $indent, $root, $item);
        $this->assertInternalType('string', $xml);
        $this->assertNotEmpty($xml);

        if ($headless) {
            $this->assertNotContains('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        } else {
            $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        }

        if ($indent) {
            $this->assertGreaterThanOrEqual(preg_match('#\n#', $xml), 2);
        } else {
            $this->assertLessThanOrEqual(preg_match('#\n#', $xml), 0);
        }

        $tag = preg_quote($root);
        $pattern = '#(?:<\?xml[^>]+\?>\n?)?<'.$tag.'>.*</'.$tag.'>\n?#smu';
        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression($pattern, $xml);
        } else {
            $this->assertRegExp($pattern, $xml);
        }
    }
}

// 为 PHP 5.6 兼容性创建测试类
class StringableTestClass
{
    public function __toString()
    {
        return json_encode(array('type' => 'MERCHANT_ID', 'account' => '190001001')) ?: '';
    }
}
