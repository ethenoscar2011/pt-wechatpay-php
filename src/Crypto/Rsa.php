<?php

namespace PtWeChatPay\Crypto;

use UnexpectedValueException;
use function array_column;
use function array_combine;
use function array_keys;
use function base64_decode;
use function base64_encode;
use function gettype;
use function is_int;
use function is_string;
use function ltrim;
use function openssl_pkey_get_private;
use function openssl_pkey_get_public;
use function openssl_private_decrypt;
use function openssl_public_encrypt;
use function openssl_sign;
use function openssl_verify;
use function pack;
use function parse_url;
use function preg_match;
use function sprintf;
use function str_replace;
use function strlen;
use function strpos;
use function substr;
use function wordwrap;
use const OPENSSL_ALGO_SHA256;
use const OPENSSL_PKCS1_OAEP_PADDING;
use const PHP_URL_SCHEME;

/**
 * RSA `PKEY` loader and encrypt/decrypt/sign/verify methods.
 */
class Rsa
{
    /** @var string - Type string of the asymmetric key */
    const KEY_TYPE_PUBLIC = 'public';
    /** @var string - Type string of the asymmetric key */
    const KEY_TYPE_PRIVATE = 'private';

    private static $LOCAL_FILE_PROTOCOL = 'file://';
    private static $PKEY_PEM_NEEDLE = ' KEY-';
    private static $PKEY_PEM_FORMAT = "-----BEGIN %1\$s KEY-----\n%2\$s\n-----END %1\$s KEY-----";
    private static $PKEY_PEM_FORMAT_PATTERN = '#-{5}BEGIN ((?:RSA )?(?:PUBLIC|PRIVATE)) KEY-{5}\r?\n([^-]+)\r?\n-{5}END \1 KEY-{5}#';
    private static $CHR_CR = "\r";
    private static $CHR_LF = "\n";

    /** @var array - Supported loading rules */
    private static $RULES = array(
        'private.pkcs1' => array("-----BEGIN %1\$s KEY-----\n%2\$s\n-----END %1\$s KEY-----", 'RSA PRIVATE', 16),
        'private.pkcs8' => array("-----BEGIN %1\$s KEY-----\n%2\$s\n-----END %1\$s KEY-----", 'PRIVATE', 16),
        'public.pkcs1' => array("-----BEGIN %1\$s KEY-----\n%2\$s\n-----END %1\$s KEY-----", 'RSA PUBLIC', 15),
        'public.spki' => array("-----BEGIN %1\$s KEY-----\n%2\$s\n-----END %1\$s KEY-----", 'PUBLIC', 14),
    );

    /**
     * @var string - Equal to `sequence(oid(1.2.840.113549.1.1.1), null))`
     * @link https://datatracker.ietf.org/doc/html/rfc3447#appendix-A.2
     */
    private static $ASN1_OID_RSAENCRYPTION = '300d06092a864886f70d0101010500';
    private static $ASN1_SEQUENCE = 48;
    private static $CHR_NUL = "\0";
    private static $CHR_ETX = "\3";

    /**
     * Translate the \$thing strlen from `X690` style to the `ASN.1` 128bit hexadecimal length string
     *
     * @param string $thing - The string
     *
     * @return string The `ASN.1` 128bit hexadecimal length string
     */
    private static function encodeLength($thing)
    {
        $num = strlen($thing);
        if ($num <= 0x7F) {
            return sprintf('%c', $num);
        }

        $tmp = ltrim(pack('N', $num), self::$CHR_NUL);

        return pack('Ca*', strlen($tmp) | 0x80, $tmp);
    }

    /**
     * Convert the `PKCS#1` format RSA Public Key to `SPKI` format
     *
     * @param string $thing - The base64-encoded string, without evelope style
     *
     * @return string The `SPKI` style public key without evelope string
     */
    public static function pkcs1ToSpki($thing)
    {
        $raw = self::$CHR_NUL.base64_decode($thing);
        $new = pack('H*', self::$ASN1_OID_RSAENCRYPTION).self::$CHR_ETX.self::encodeLength($raw).$raw;

        return base64_encode(pack('Ca*a*', self::$ASN1_SEQUENCE, self::encodeLength($new), $new));
    }

    /**
     * Sugar for loading input `privateKey` string, pure `base64-encoded-string` without LF and evelope.
     *
     * @param string $thing - The string in `PKCS#8` format.
     * @return mixed
     * @throws UnexpectedValueException
     */
    public static function fromPkcs8($thing)
    {
        return static::from(sprintf('private.pkcs8://%s', $thing), static::KEY_TYPE_PRIVATE);
    }

    /**
     * Sugar for loading input `privateKey/publicKey` string, pure `base64-encoded-string` without LF and evelope.
     *
     * @param string $thing - The string in `PKCS#1` format.
     * @param string $type - Either `self::KEY_TYPE_PUBLIC` or `self::KEY_TYPE_PRIVATE` string, default is `self::KEY_TYPE_PRIVATE`.
     * @return mixed
     * @throws UnexpectedValueException
     */
    public static function fromPkcs1($thing, $type = self::KEY_TYPE_PRIVATE)
    {
        return static::from(sprintf('%s://%s', $type === static::KEY_TYPE_PUBLIC ? 'public.pkcs1' : 'private.pkcs1',
            $thing), $type);
    }

    /**
     * Sugar for loading input `publicKey` string, pure `base64-encoded-string` without LF and evelope.
     *
     * @param string $thing - The string in `SKPI` format.
     * @return mixed
     * @throws UnexpectedValueException
     */
    public static function fromSpki($thing)
    {
        return static::from(sprintf('public.spki://%s', $thing), static::KEY_TYPE_PUBLIC);
    }

    /**
     * Loading the privateKey/publicKey.
     *
     * The `\$thing` can be one of the following:
     * - `file://` protocol `PKCS#1/PKCS#8 privateKey`/`SPKI publicKey`/`x509 certificate(for publicKey)` string.
     * - `public.spki://`, `public.pkcs1://`, `private.pkcs1://`, `private.pkcs8://` protocols string.
     * - full `PEM` in `PKCS#1/PKCS#8` format `privateKey`/`publicKey`/`x509 certificate(for publicKey)` string.
     * - `\OpenSSLAsymmetricKey` (PHP8) or `resource#pkey` (PHP7).
     * - `\OpenSSLCertificate` (PHP8) or `resource#X509` (PHP7) for publicKey.
     * - `Array` of `[privateKeyString,passphrase]` for encrypted privateKey.
     *
     * @param mixed $thing - The thing.
     * @param string $type - Either `self::KEY_TYPE_PUBLIC` or `self::KEY_TYPE_PRIVATE` string, default is `self::KEY_TYPE_PRIVATE`.
     *
     * @return mixed
     * @throws UnexpectedValueException
     */
    public static function from($thing, $type = self::KEY_TYPE_PRIVATE)
    {
        $isPublic = $type === static::KEY_TYPE_PUBLIC;
        $pkey = $isPublic
            ? openssl_pkey_get_public(self::parse($thing, $type))
            : openssl_pkey_get_private(self::parse($thing));

        if (false === $pkey) {
            throw new UnexpectedValueException(sprintf(
                'Cannot load %s from(%s), please take care about the \$thing input.',
                $isPublic ? 'publicKey' : 'privateKey',
                gettype($thing)
            ));
        }

        return $pkey;
    }

    /**
     * Parse the `\$thing` for the `openssl_pkey_get_public`/`openssl_pkey_get_private` function.
     *
     * The `\$thing` can be the `file://` protocol privateKey/publicKey string, eg:
     *   - `file:///my/path/to/private.pkcs1.key`
     *   - `file:///my/path/to/private.pkcs8.key`
     *   - `file:///my/path/to/public.spki.pem`
     *   - `file:///my/path/to/x509.crt` (for publicKey)
     *
     * The `\$thing` can be the `public.spki://`, `public.pkcs1://`, `private.pkcs1://`, `private.pkcs8://` protocols string, eg:
     *   - `public.spki://MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCg...`
     *   - `public.pkcs1://MIIBCgKCAQEAgYxTW5Yj...`
     *   - `private.pkcs1://MIIEpAIBAAKCAQEApdXuft3as2x...`
     *   - `private.pkcs8://MIIEpAIBAAKCAQEApdXuft3as2x...`
     *
     * The `\$thing` can be the string with PEM `evelope`, eg:
     *   - `-----BEGIN RSA PRIVATE KEY-----...-----END RSA PRIVATE KEY-----`
     *   - `-----BEGIN PRIVATE KEY-----...-----END PRIVATE KEY-----`
     *   - `-----BEGIN RSA PUBLIC KEY-----...-----END RSA PUBLIC KEY-----`
     *   - `-----BEGIN PUBLIC KEY-----...-----END PUBLIC KEY-----`
     *   - `-----BEGIN CERTIFICATE-----...-----END CERTIFICATE-----` (for publicKey)
     *
     * The `\$thing` can be the \OpenSSLAsymmetricKey/\OpenSSLCertificate/resouce, eg:
     *   - `\OpenSSLAsymmetricKey` (PHP8) or `resource#pkey` (PHP7) for publicKey/privateKey.
     *   - `\OpenSSLCertificate` (PHP8) or `resource#X509` (PHP7) for publicKey.
     *
     * The `\$thing` can be the Array{$privateKey,$passphrase} style for loading privateKey, eg:
     *   - [`file:///my/path/to/encrypted.private.pkcs8.key`, 'your_pass_phrase']
     *   - [`-----BEGIN ENCRYPTED PRIVATE KEY-----...-----END ENCRYPTED PRIVATE KEY-----`, 'your_pass_phrase']
     *
     * @param mixed $thing - The thing.
     * @param string $type - Either `self::KEY_TYPE_PUBLIC` or `self::KEY_TYPE_PRIVATE` string, default is `self::KEY_TYPE_PRIVATE`.
     * @return mixed
     */
    private static function parse($thing, $type = self::KEY_TYPE_PRIVATE)
    {
        $src = $thing;

        if (is_string($src) && is_int(strpos($src, self::$PKEY_PEM_NEEDLE))
            && $type === static::KEY_TYPE_PUBLIC && preg_match(self::$PKEY_PEM_FORMAT_PATTERN, $src, $matches)) {
            $kind = $matches[1];
            $base64 = $matches[2];
            $mapRules = array_combine(array_column(self::$RULES, 1), array_keys(self::$RULES));
            $protocol = isset($mapRules[$kind]) ? $mapRules[$kind] : '';
            if ('public.pkcs1' === $protocol) {
                $src = sprintf('%s://%s', $protocol, str_replace(array(self::$CHR_CR, self::$CHR_LF), '', $base64));
            }
        }

        if (is_string($src) && is_bool(strpos($src, self::$LOCAL_FILE_PROTOCOL)) && is_int(strpos($src, '://'))) {
            $protocol = parse_url($src, PHP_URL_SCHEME);
            $rule = isset(self::$RULES[$protocol]) ? self::$RULES[$protocol] : array(null, null, null);
            $format = $rule[0];
            $kind = $rule[1];
            $offset = $rule[2];
            if ($format && $kind && $offset) {
                $src = substr($src, $offset);
                if ('public.pkcs1' === $protocol) {
                    $src = static::pkcs1ToSpki($src);
                    $rule = self::$RULES['public.spki'];
                    $format = $rule[0];
                    $kind = $rule[1];
                }

                return sprintf($format, $kind, wordwrap($src, 64, self::$CHR_LF, true));
            }
        }

        return $src;
    }

    /**
     * Check the padding mode whether or nor supported.
     *
     * @param int $padding - The padding mode, only support `OPENSSL_PKCS1_PADDING`, otherwise thrown `\UnexpectedValueException`.
     *
     * @throws UnexpectedValueException
     */
    private static function paddingModeLimitedCheck($padding)
    {
        if ($padding !== OPENSSL_PKCS1_OAEP_PADDING) {
            throw new UnexpectedValueException(sprintf('Here\'s only support the OPENSSL_PKCS1_OAEP_PADDING(4) mode, yours(%d).',
                $padding));
        }
    }

    /**
     * Encrypts text by the given `$publicKey` in the `$padding`(default is `OPENSSL_PKCS1_OAEP_PADDING`) mode.
     *
     * @param string $plaintext - Cleartext to encode.
     * @param mixed $publicKey - The public key.
     * @param int $padding - default is `OPENSSL_PKCS1_OAEP_PADDING`.
     *
     * @return string - The base64-encoded ciphertext.
     * @throws UnexpectedValueException
     */
    public static function encrypt($plaintext, $publicKey, $padding = OPENSSL_PKCS1_OAEP_PADDING)
    {
        self::paddingModeLimitedCheck($padding);

        if (!openssl_public_encrypt($plaintext, $encrypted, $publicKey, $padding)) {
            throw new UnexpectedValueException('Encrypting the input $plaintext failed, please checking your $publicKey whether or nor correct.');
        }

        return base64_encode($encrypted);
    }

    /**
     * Verifying the `message` with given `signature` string that uses `OPENSSL_ALGO_SHA256`.
     *
     * @param string $message - Content will be `openssl_verify`.
     * @param string $signature - The base64-encoded ciphertext.
     * @param mixed $publicKey - The public key.
     *
     * @return boolean - True is passed, false is failed.
     * @throws UnexpectedValueException
     */
    public static function verify($message, $signature, $publicKey)
    {
        $result = openssl_verify($message, base64_decode($signature), $publicKey, OPENSSL_ALGO_SHA256);
        if ($result === false) {
            throw new UnexpectedValueException('Verified the input $message failed, please checking your $publicKey whether or nor correct.');
        }

        return $result === 1;
    }

    /**
     * Creates and returns a `base64_encode` string that uses `OPENSSL_ALGO_SHA256`.
     *
     * @param string $message - Content will be `openssl_sign`.
     * @param mixed $privateKey - The private key.
     *
     * @return string - The base64-encoded signature.
     * @throws UnexpectedValueException
     */
    public static function sign($message, $privateKey)
    {
        if (!openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new UnexpectedValueException('Signing the input $message failed, please checking your $privateKey whether or nor correct.');
        }

        return base64_encode($signature);
    }

    /**
     * Decrypts base64 encoded string with `$privateKey` in the `$padding`(default is `OPENSSL_PKCS1_OAEP_PADDING`) mode.
     *
     * @param string $ciphertext - Was previously encrypted string using the corresponding public key.
     * @param mixed $privateKey - The private key.
     * @param int $padding - default is `OPENSSL_PKCS1_OAEP_PADDING`.
     *
     * @return string - The utf-8 plaintext.
     * @throws UnexpectedValueException
     */
    public static function decrypt($ciphertext, $privateKey, $padding = OPENSSL_PKCS1_OAEP_PADDING)
    {
        self::paddingModeLimitedCheck($padding);

        if (!openssl_private_decrypt(base64_decode($ciphertext), $decrypted, $privateKey, $padding)) {
            throw new UnexpectedValueException('Decrypting the input $ciphertext failed, please checking your $privateKey whether or nor correct.');
        }

        return $decrypted;
    }
}
