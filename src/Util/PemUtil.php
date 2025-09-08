<?php

namespace PtWeChatPay\Util;

use PtWeChatPay\Crypto\Rsa;
use UnexpectedValueException;
use function file_get_contents;
use function openssl_x509_parse;
use function openssl_x509_read;
use function strpos;
use function strtoupper;

/**
 * Util for read private key and certificate.
 */
class PemUtil
{
    private static $LOCAL_FILE_PROTOCOL = 'file://';

    /**
     * Read private key from file
     * @param string $filepath - PEM encoded private key file path
     *
     * @return mixed
     * @deprecated v1.2.0 - Use `Rsa::from` instead
     *
     */
    public static function loadPrivateKey($filepath)
    {
        return Rsa::from((false === strpos($filepath,
                self::$LOCAL_FILE_PROTOCOL) ? self::$LOCAL_FILE_PROTOCOL : '').$filepath);
    }

    /**
     * Read private key from string
     * @param mixed $content - PEM encoded private key string content
     *
     * @return mixed
     * @deprecated v1.2.0 - Use `Rsa::from` instead
     *
     */
    public static function loadPrivateKeyFromString($content)
    {
        return Rsa::from($content);
    }

    /**
     * Read certificate from file
     *
     * @param string $filepath - PEM encoded X.509 certificate file path
     *
     * @return mixed - X.509 certificate resource identifier on success or FALSE on failure
     * @throws UnexpectedValueException
     */
    public static function loadCertificate($filepath)
    {
        $content = file_get_contents($filepath);
        if (false === $content) {
            throw new UnexpectedValueException("Loading the certificate failed, please checking your {$filepath} input.");
        }

        return openssl_x509_read($content);
    }

    /**
     * Read certificate from string
     *
     * @param mixed $content - PEM encoded X.509 certificate string content
     *
     * @return mixed - X.509 certificate resource identifier on success or FALSE on failure
     */
    public static function loadCertificateFromString($content)
    {
        return openssl_x509_read($content);
    }

    /**
     * Parse Serial Number from Certificate
     *
     * @param mixed $certificate Certificates string or resource
     *
     * @return string - The serial number
     * @throws UnexpectedValueException
     */
    public static function parseCertificateSerialNo($certificate)
    {
        $info = openssl_x509_parse($certificate);
        if (false === $info) {
            throw new UnexpectedValueException('Read the $certificate failed, please check it whether or nor correct');
        }

        // PHP 5.6 uses 'serialNumber', PHP 7+ uses 'serialNumberHex'
        if (isset($info['serialNumberHex'])) {
            return strtoupper($info['serialNumberHex']);
        } elseif (isset($info['serialNumber'])) {
            // Convert decimal serial number to hex
            return strtoupper(dechex($info['serialNumber']));
        } else {
            throw new UnexpectedValueException('Certificate serial number not found');
        }
    }
}
