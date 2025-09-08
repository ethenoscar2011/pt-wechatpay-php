<?php

namespace PtWeChatPay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use function abs;
use function array_key_exists;
use function array_keys;
use function count;
use function implode;
use function intval;
use function is_array;
use function is_object;
use function is_resource;
use function is_string;
use function sprintf;
use function strcasecmp;
use function strncasecmp;

/** @var int - The maximum clock offset in second */
define('MAXIMUM_CLOCK_OFFSET', 300);

define('WechatpayNonce', 'Wechatpay-Nonce');
define('WechatpaySerial', 'Wechatpay-Serial');
define('WechatpaySignature', 'Wechatpay-Signature');
define('WechatpayTimestamp', 'Wechatpay-Timestamp');
define('WechatpayStatementSha1', 'Wechatpay-Statement-Sha1');

/**
 * JSON based Client interface for sending HTTP requests.
 */
trait ClientJsonTrait
{
    /**
     * @var array - The defaults configuration whose pased in `GuzzleHttp\Client`.
     */
    protected static $defaults = array(
        'base_uri' => 'https://api.mch.weixin.qq.com/',
        'headers' => array(
            'Accept' => 'application/json, text/plain, application/x-gzip, application/pdf, image/png, image/*;q=0.5',
            'Content-Type' => 'application/json; charset=utf-8',
        ),
    );

    // These methods should be implemented by the class using this trait
    // For PHP 5.6 compatibility, we cannot use abstract static methods in traits

    /**
     * APIv3's signer middleware stack
     *
     * @param string $mchid - The merchant ID
     * @param string $serial - The serial number of the merchant certificate
     * @param mixed $privateKey - The merchant private key.
     *
     * @return callable
     */
    public static function signer($mchid, $serial, $privateKey)
    {
        return function (RequestInterface $request) use ($mchid, $serial, $privateKey) {
            $nonce = Formatter::nonce();
            $timestamp = (string)Formatter::timestamp();
            $signature = Crypto\Rsa::sign(Formatter::request(
                $request->getMethod(), $request->getRequestTarget(), $timestamp, $nonce, static::body($request)
            ), $privateKey);

            return $request->withHeader('Authorization', Formatter::authorization(
                $mchid, $nonce, $signature, $timestamp, $serial
            ));
        };
    }

    /**
     * Assert the HTTP `20X` responses fit for the business logic, otherwise thrown a `\GuzzleHttp\Exception\RequestException`.
     *
     * The `30X` responses were handled by `\GuzzleHttp\RedirectMiddleware`.
     * The `4XX, 5XX` responses were handled by `\GuzzleHttp\Middleware::httpErrors`.
     *
     * @param array $certs The wechatpay platform serial and certificate(s), `[$serial => $cert]` pair
     * @return callable
     * @throws RequestException
     */
    protected static function assertSuccessfulResponse(array &$certs)
    {
        return function (ResponseInterface $response, RequestInterface $request) use (&$certs) {
            $url = $request->getUri()->getPath();
            if (
                0 === strcasecmp($url, '/v3/billdownload/file')
                || (0 === strncasecmp($url, '/v3/merchant-service/images/', 28) && 0 !== strcasecmp($url,
                        '/v3/merchant-service/images/upload'))
            ) {
                return $response;
            }

            if (!($response->hasHeader(WechatpayNonce) && $response->hasHeader(WechatpaySerial)
                && $response->hasHeader(WechatpaySignature) && $response->hasHeader(WechatpayTimestamp))) {
                throw new RequestException(sprintf(
                    Exception\WeChatPayException::EV3_RES_HEADERS_INCOMPLETE,
                    WechatpayNonce, WechatpaySerial, WechatpaySignature, WechatpayTimestamp
                ), $request, $response);
            }

            $nonceHeaders = $response->getHeader(WechatpayNonce);
            $serialHeaders = $response->getHeader(WechatpaySerial);
            $signatureHeaders = $response->getHeader(WechatpaySignature);
            $timestampHeaders = $response->getHeader(WechatpayTimestamp);

            $nonce = $nonceHeaders[0];
            $serial = $serialHeaders[0];
            $signature = $signatureHeaders[0];
            $timestamp = $timestampHeaders[0];

            $localTimestamp = Formatter::timestamp();

            if (abs($localTimestamp - intval($timestamp)) > MAXIMUM_CLOCK_OFFSET) {
                throw new RequestException(sprintf(
                    Exception\WeChatPayException::EV3_RES_HEADER_TIMESTAMP_OFFSET,
                    MAXIMUM_CLOCK_OFFSET, $timestamp, $localTimestamp
                ), $request, $response);
            }

            if (!array_key_exists($serial, $certs)) {
                throw new RequestException(sprintf(
                    Exception\WeChatPayException::EV3_RES_HEADER_PLATFORM_SERIAL,
                    $serial, WechatpaySerial, implode(',', array_keys($certs))
                ), $request, $response);
            }

            $isOverseas = (0 === strcasecmp($url, '/hk/v3/statements') || 0 === strcasecmp($url,
                        '/v3/global/statements')) && $response->hasHeader(WechatpayStatementSha1);

            $verified = false;
            $exception = null;
            try {
                $verified = Crypto\Rsa::verify(
                    Formatter::response(
                        $timestamp,
                        $nonce,
                        $isOverseas ? static::digestBody($response) : static::body($response)
                    ),
                    $signature, $certs[$serial]
                );
            } catch (\Exception $e) {
                $exception = $e;
            }
            if ($verified === false) {
                throw new RequestException(sprintf(
                    Exception\WeChatPayException::EV3_RES_HEADER_SIGNATURE_DIGEST,
                    $timestamp, $nonce, $signature, $serial
                ), $request, $response, $exception);
            }

            return $response;
        };
    }

    /**
     * Downloading the reconciliation was required the client to format the `WechatpayStatementSha1` digest string as `JSON`.
     *
     * There was also sugguestion that to validate the response streaming's `SHA1` digest whether or nor equals to `WechatpayStatementSha1`.
     * Here may contains with or without `gzip` parameter. Both of them are validating the plain `CSV` stream.
     * Keep the same logic with the mainland's one(without `SHA1` validation).
     * If someone needs this feature built-in, contrubiting is welcome.
     *
     * @see https://pay.weixin.qq.com/wiki/doc/api/wxpay/ch/fusion_wallet_ch/QuickPay/chapter8_5.shtml
     * @see https://pay.weixin.qq.com/wiki/doc/api/wxpay/en/fusion_wallet/QuickPay/chapter8_5.shtml
     * @see https://pay.weixin.qq.com/wiki/doc/api_external/ch/apis/chapter3_1_6.shtml
     * @see https://pay.weixin.qq.com/wiki/doc/api_external/en/apis/chapter3_1_6.shtml
     *
     * @param ResponseInterface $response - The response instance
     *
     * @return string - The JSON string
     */
    protected static function digestBody(ResponseInterface $response)
    {
        $headers = $response->getHeader(WechatpayStatementSha1);

        return sprintf('{"sha1":"%s"}', $headers[0]);
    }

    /**
     * APIv3's verifier middleware stack
     *
     * @param array $certs The wechatpay platform serial and certificate(s), `[$serial => $cert]` pair
     * @return callable
     */
    public static function verifier(array &$certs)
    {
        $assert = static::assertSuccessfulResponse($certs);

        return function (callable $handler) use ($assert) {
            return function (RequestInterface $request, array $options = array()) use ($assert, $handler) {
                return $handler($request, $options)->then(function (ResponseInterface $response) use (
                    $assert,
                    $request
                ) {
                    return $assert($response, $request);
                });
            };
        };
    }

    /**
     * Create an APIv3's client
     *
     * Mandatory \$config array paramters
     *   - mchid: string - The merchant ID
     *   - serial: string - The serial number of the merchant certificate
     *   - privateKey: mixed - The merchant private key.
     *   - certs: array - The wechatpay platform serial and certificate(s), `[$serial => $cert]` pair
     *
     * @param array $config - The configuration
     * @throws \PtWeChatPay\Exception\InvalidArgumentException
     */
    public static function jsonBased(array $config = array())
    {
        if (!(
            isset($config['mchid']) && is_string($config['mchid'])
        )) {
            throw new Exception\InvalidArgumentException(\ERR_INIT_MCHID_IS_MANDATORY);
        }

        if (!(
            isset($config['serial']) && is_string($config['serial'])
        )) {
            throw new Exception\InvalidArgumentException(\ERR_INIT_SERIAL_IS_MANDATORY);
        }

        if (!(
            isset($config['privateKey']) && (is_string($config['privateKey']) || is_resource($config['privateKey']) || is_object($config['privateKey']))
        )) {
            throw new Exception\InvalidArgumentException(\ERR_INIT_PRIVATEKEY_IS_MANDATORY);
        }

        if (!(
            isset($config['certs']) && is_array($config['certs']) && count($config['certs'])
        )) {
            throw new Exception\InvalidArgumentException(\ERR_INIT_CERTS_IS_MANDATORY);
        }

        if (array_key_exists($config['serial'], $config['certs'])) {
            throw new Exception\InvalidArgumentException(sprintf(
                \ERR_INIT_CERTS_EXCLUDE_MCHSERIAL, implode(',', array_keys($config['certs'])), $config['serial']
            ));
        }

        /** @var HandlerStack $stack */
        $stack = isset($config['handler']) && ($config['handler'] instanceof HandlerStack) ? (clone $config['handler']) : HandlerStack::create();
        $stack->before('prepare_body',
            Middleware::mapRequest(static::signer((string)$config['mchid'], $config['serial'], $config['privateKey'])),
            'signer');
        $stack->before('http_errors', static::verifier($config['certs']), 'verifier');
        $config['handler'] = $stack;

        unset($config['mchid'], $config['serial'], $config['privateKey'], $config['certs'], $config['secret'], $config['merchant']);

        return new Client(static::withDefaults($config));
    }
}
