<?php

namespace PtWeChatPay;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Query;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use function array_key_exists;
use function in_array;
use function sprintf;
use function strlen;

/**
 * XML based Client interface for sending HTTP requests.
 */
trait ClientXmlTrait
{
    /**
     * @var array - The default headers whose passed in `GuzzleHttp\Client`.
     */
    protected static $headers = array(
        'Accept' => 'text/xml, text/plain, application/x-gzip',
        'Content-Type' => 'text/xml; charset=utf-8',
    );

    /**
     * @var array - Special URLs whose were designed that none signature respond.
     */
    protected static $noneSignatureRespond = array(
        '/mchrisk/querymchrisk',
        '/mchrisk/setmchriskcallback',
        '/mchrisk/syncmchriskresult',
        '/mmpaymkttransfers/gethbinfo',
        '/mmpaymkttransfers/gettransferinfo',
        '/mmpaymkttransfers/pay_bank',
        '/mmpaymkttransfers/promotion/paywwsptrans2pocket',
        '/mmpaymkttransfers/promotion/querywwsptrans2pocket',
        '/mmpaymkttransfers/promotion/transfers',
        '/mmpaymkttransfers/query_bank',
        '/mmpaymkttransfers/sendgroupredpack',
        '/mmpaymkttransfers/sendminiprogramhb',
        '/mmpaymkttransfers/sendredpack',
        '/papay/entrustweb',
        '/papay/h5entrustweb',
        '/papay/partner/entrustweb',
        '/papay/partner/h5entrustweb',
        '/pay/downloadbill',
        '/pay/downloadfundflow',
        '/payitil/report',
        '/risk/getpublickey',
        '/risk/getviolation',
        '/secapi/mch/submchmanage',
        '/xdc/apiv2getsignkey/sign/getsignkey',
    );

    // These methods should be implemented by the class using this trait
    // For PHP 5.6 compatibility, we cannot use abstract static methods in traits

    /**
     * APIv2's transformRequest, did the `datasign` and `array2xml` together
     *
     * @param string|null $mchid - The merchant ID
     * @param string $secret - The secret key string (optional)
     * @param array $merchant - The merchant private key and certificate array. (optional)
     *
     * @return callable
     * @throws \PtWeChatPay\Exception\InvalidArgumentException
     */
    public static function transformRequest($mchid = null, $secret = '', $merchant = null)
    {
        return function (callable $handler) use ($mchid, $secret, $merchant) {
            return function (RequestInterface $request, array $options = array()) use (
                $handler,
                $mchid,
                $secret,
                $merchant
            ) {
                $methodIsGet = $request->getMethod() === 'GET';

                if ($methodIsGet) {
                    $queryParams = Query::parse($request->getUri()->getQuery());
                }

                $data = isset($options['xml']) ? $options['xml'] : (isset($queryParams) ? $queryParams : array());

                if ($mchid && $mchid !== ($inputMchId = isset($data['mch_id']) ? $data['mch_id'] : (isset($data['mchid']) ? $data['mchid'] : (isset($data['combine_mch_id']) ? $data['combine_mch_id'] : null)))) {
                    throw new Exception\InvalidArgumentException(sprintf(\EV2_REQ_XML_NOTMATCHED_MCHID,
                        $inputMchId ?: '', $mchid));
                }

                $type = isset($data['sign_type']) ? $data['sign_type'] : Crypto\Hash::ALGO_MD5;

                if (!isset($options['nonceless'])) {
                    $data['nonce_str'] = isset($data['nonce_str']) ? $data['nonce_str'] : Formatter::nonce();
                }

                $data['sign'] = Crypto\Hash::sign($type, Formatter::queryStringLike(Formatter::ksort($data)), $secret);

                $modify = $methodIsGet ? array('query' => Query::build($data)) : array('body' => Transformer::toXml($data));

                // for security request, it was required the merchant's private_key and certificate
                if (isset($options['security']) && true === $options['security']) {
                    $options['ssl_key'] = isset($merchant['key']) ? $merchant['key'] : null;
                    $options['cert'] = isset($merchant['cert']) ? $merchant['cert'] : null;
                }

                unset($options['xml'], $options['nonceless'], $options['security']);

                return $handler(Utils::modifyRequest($request, $modify), $options);
            };
        };
    }

    /**
     * APIv2's transformResponse, doing the `xml2array` then `verify` the signature job only
     *
     * @param string $secret - The secret key string (optional)
     *
     * @return callable
     */
    public static function transformResponse($secret = '')
    {
        return function (callable $handler) use ($secret) {
            return function (RequestInterface $request, array $options = array()) use ($secret, $handler) {
                if (in_array($request->getUri()->getPath(), static::$noneSignatureRespond)) {
                    return $handler($request, $options);
                }

                return $handler($request, $options)->then(function (ResponseInterface $response) use ($secret) {
                    $result = Transformer::toArray(static::body($response));

                    if (!(array_key_exists('return_code', $result) && Crypto\Hash::equals('SUCCESS',
                            $result['return_code']))) {
                        return Create::rejectionFor($response);
                    }

                    if (array_key_exists('result_code', $result) && !Crypto\Hash::equals('SUCCESS',
                            $result['result_code'])) {
                        return Create::rejectionFor($response);
                    }

                    /** @var string|null $sign */
                    $sign = isset($result['sign']) ? $result['sign'] : null;
                    $type = $sign && strlen($sign) === 64 ? Crypto\Hash::ALGO_HMAC_SHA256 : Crypto\Hash::ALGO_MD5;
                    /** @var string $calc - calculated digest string, it's naver `null` here because of \$type known. */
                    $calc = Crypto\Hash::sign($type, Formatter::queryStringLike(Formatter::ksort($result)), $secret);

                    return Crypto\Hash::equals($calc, $sign) ? $response : Create::rejectionFor($response);
                });
            };
        };
    }

    /**
     * Create an APIv2's client
     *
     * @param array $config - The configuration
     * @deprecated 1.0 - @see \PtWeChatPay\Exception\WeChatPayException::DEP_XML_PROTOCOL_IS_REACHABLE_EOL
     *
     * Optional acceptable \$config parameters
     *   - mchid?: string|null - The merchant ID
     *   - secret?: string|null - The secret key string
     *   - merchant?: array - The merchant private key and certificate array. (optional)
     *
     */
    public static function xmlBased(array $config = array())
    {
        /** @var HandlerStack $stack */
        $stack = isset($config['handler']) && ($config['handler'] instanceof HandlerStack) ? (clone $config['handler']) : HandlerStack::create();
        $stack->before('prepare_body', static::transformRequest(isset($config['mchid']) ? $config['mchid'] : null,
            isset($config['secret']) ? $config['secret'] : '',
            isset($config['merchant']) ? $config['merchant'] : array()), 'transform_request');
        $stack->before('http_errors', static::transformResponse(isset($config['secret']) ? $config['secret'] : ''),
            'transform_response');
        $config['handler'] = $stack;

        unset($config['mchid'], $config['serial'], $config['privateKey'], $config['certs'], $config['secret'], $config['merchant']);

        return new Client(static::withDefaults(array('headers' => static::$headers), $config));
    }
}
