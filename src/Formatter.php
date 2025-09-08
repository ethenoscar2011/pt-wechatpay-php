<?php

namespace PtWeChatPay;

use InvalidArgumentException;
use function array_map;
use function array_merge;
use function implode;
use function is_null;
use function ksort;
use function ord;
use function random_bytes;
use function sprintf;
use function str_split;
use function time;
use const SORT_STRING;

/**
 * Provides easy used methods using in this project.
 */
class Formatter
{
    /**
     * Generate a random BASE62 string aka `nonce`, similar as `random_bytes`.
     *
     * @param int $size - Nonce string length, default is 32.
     *
     * @return string - base62 random string.
     */
    public static function nonce($size = 32)
    {
        if ($size < 1) {
            throw new InvalidArgumentException('Size must be a positive integer.');
        }

        // PHP 5.6 兼容性：使用 openssl_random_pseudo_bytes 替代 random_bytes
        if (function_exists('random_bytes')) {
            $bytes = random_bytes($size);
        } else {
            $bytes = openssl_random_pseudo_bytes($size);
        }

        return implode('', array_map(function ($c) {
            return '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz'[ord($c) % 62];
        }, str_split($bytes)));
    }

    /**
     * Retrieve the current `Unix` timestamp.
     *
     * @return int - Epoch timestamp.
     */
    public static function timestamp()
    {
        return time();
    }

    /**
     * Formatting for the heading `Authorization` value.
     *
     * @param string $mchid - The merchant ID.
     * @param string $nonce - The Nonce string.
     * @param string $signature - The base64-encoded `Rsa::sign` ciphertext.
     * @param string $timestamp - The `Unix` timestamp.
     * @param string $serial - The serial number of the merchant public certification.
     *
     * @return string - The APIv3 Authorization `header` value
     */
    public static function authorization($mchid, $nonce, $signature, $timestamp, $serial)
    {
        return sprintf(
            'WECHATPAY2-SHA256-RSA2048 mchid="%s",serial_no="%s",timestamp="%s",nonce_str="%s",signature="%s"',
            $mchid, $serial, $timestamp, $nonce, $signature
        );
    }

    /**
     * Formatting this `HTTP::request` for `Rsa::sign` input.
     *
     * @param string $method - The HTTP verb, must be the uppercase sting.
     * @param string $uri - Combined string with `URL::pathname` and `URL::search`.
     * @param string $timestamp - The `Unix` timestamp, should be the one used in `authorization`.
     * @param string $nonce - The `Nonce` string, should be the one used in `authorization`.
     * @param string $body - The playload string, HTTP `GET` should be an empty string.
     *
     * @return string - The content for `Rsa::sign`
     */
    public static function request($method, $uri, $timestamp, $nonce, $body = '')
    {
        return static::joinedByLineFeed($method, $uri, $timestamp, $nonce, $body);
    }

    /**
     * Formatting this `HTTP::response` for `Rsa::verify` input.
     *
     * @param string $timestamp - The `Unix` timestamp, should be the one from `response::headers[Wechatpay-Timestamp]`.
     * @param string $nonce - The `Nonce` string, should be the one from `response::headers[Wechatpay-Nonce]`.
     * @param string $body - The response payload string, HTTP status(`201`, `204`) should be an empty string.
     *
     * @return string - The content for `Rsa::verify`
     */
    public static function response($timestamp, $nonce, $body = '')
    {
        return static::joinedByLineFeed($timestamp, $nonce, $body);
    }

    /**
     * Joined this inputs by for `Line Feed`(LF) char.
     *
     * @param mixed $pieces - The scalar variable(s).
     *
     * @return string - The joined string.
     */
    public static function joinedByLineFeed()
    {
        $pieces = func_get_args();

        return implode("\n", array_merge($pieces, array('')));
    }

    /**
     * Sort an array by key with `SORT_STRING` flag.
     *
     * @param array $thing - The input array.
     *
     * @return array - The sorted array.
     */
    public static function ksort(array $thing = array())
    {
        ksort($thing, SORT_STRING);

        return $thing;
    }

    /**
     * Like `queryString` does but without the `sign` and `empty value` entities.
     *
     * @param array $thing - The input array.
     *
     * @return string - The `key=value` pair string whose joined by `&` char.
     */
    public static function queryStringLike(array $thing = array())
    {
        $data = array();

        foreach ($thing as $key => $value) {
            if ($key === 'sign' || is_null($value) || $value === '') {
                continue;
            }
            $data[] = implode('=', array($key, $value));
        }

        return implode('&', $data);
    }
}
