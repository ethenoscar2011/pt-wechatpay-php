<?php

namespace PtWeChatPay\Crypto;

use RuntimeException;
use UnexpectedValueException;
use function base64_decode;
use function base64_encode;
use function hash_equals;
use function hash_hmac;
use function in_array;
use function openssl_decrypt;
use function openssl_encrypt;
use function openssl_get_cipher_methods;
use function strlen;
use function substr;
use const OPENSSL_RAW_DATA;

/**
 * Aes encrypt/decrypt using `aes-256-gcm` algorithm with additional authenticated data(`aad`).
 * Compatible with PHP 5.6+ by using a custom GCM implementation when OpenSSL GCM is not available.
 */
class AesGcm implements AesInterface
{
    /**
     * Detect the ext-openssl whether or nor including the `aes-256-gcm` algorithm
     *
     * @throws RuntimeException
     */
    private static function preCondition()
    {
        // For PHP 5.6, we'll use our custom GCM implementation
        if (version_compare(PHP_VERSION, '7.1.0', '<')) {
            return; // Skip OpenSSL GCM check for PHP 5.6
        }

        if (!in_array(static::ALGO_AES_256_GCM, openssl_get_cipher_methods())) {
            throw new RuntimeException('It looks like the ext-openssl extension missing the `aes-256-gcm` cipher method.');
        }
    }

    /**
     * Check if we can use native OpenSSL GCM
     *
     * NOTE: We force the use of custom implementation for cross-version compatibility
     * because PHP 5.6's OpenSSL GCM support is limited and not compatible with PHP 7+
     *
     * @return bool
     */
    private static function canUseNativeGcm()
    {
        // Force custom implementation for cross-version compatibility
        return false;

        // Original logic (commented out for compatibility):
        // return version_compare(PHP_VERSION, '7.1.0', '>=') && 
        //        in_array(static::ALGO_AES_256_GCM, openssl_get_cipher_methods());
    }

    /**
     * Encrypts given data with given key, iv and aad, returns a base64 encoded string.
     *
     * @param string $plaintext - Text to encode.
     * @param string $key - The secret key, 32 bytes string.
     * @param string $iv - The initialization vector, 16 bytes string.
     * @param string $aad - The additional authenticated data, maybe empty string.
     *
     * @return string - The base64-encoded ciphertext.
     */
    public static function encrypt($plaintext, $key, $iv = '', $aad = '')
    {
        self::preCondition();

        if (self::canUseNativeGcm()) {
            // Use native OpenSSL GCM for PHP 7.1+
            $ciphertext = openssl_encrypt($plaintext, static::ALGO_AES_256_GCM, $key, OPENSSL_RAW_DATA, $iv, $tag, $aad,
                static::BLOCK_SIZE);

            if (false === $ciphertext) {
                throw new UnexpectedValueException('Encrypting the input $plaintext failed, please checking your $key and $iv whether or nor correct.');
            }

            return base64_encode($ciphertext.$tag);
        } else {
            // Use spomky-labs/php-aes-gcm for PHP 5.6+ compatibility
            return self::encryptWithSpomkyGcm($plaintext, $key, $iv, $aad);
        }
    }

    /**
     * Takes a base64 encoded string and decrypts it using a given key, iv and aad.
     *
     * @param string $ciphertext - The base64-encoded ciphertext.
     * @param string $key - The secret key, 32 bytes string.
     * @param string $iv - The initialization vector, 12 bytes string for GCM.
     * @param string $aad - The additional authenticated data, maybe empty string.
     *
     * @return string - The utf-8 plaintext.
     */
    public static function decrypt($ciphertext, $key, $iv = '', $aad = '')
    {
        self::preCondition();

        if (self::canUseNativeGcm()) {
            // Use native OpenSSL GCM for PHP 7.1+
            $ciphertext = base64_decode($ciphertext);
            $authTag = substr($ciphertext, $tailLength = 0 - static::BLOCK_SIZE);
            $tagLength = strlen($authTag);

            /* Manually checking the length of the tag, because the `openssl_decrypt` was mentioned there, it's the caller's responsibility. */
            if ($tagLength > static::BLOCK_SIZE || ($tagLength < 12 && $tagLength !== 8 && $tagLength !== 4)) {
                throw new RuntimeException('The inputs `$ciphertext` incomplete, the bytes length must be one of 16, 15, 14, 13, 12, 8 or 4.');
            }

            $plaintext = openssl_decrypt(substr($ciphertext, 0, $tailLength), static::ALGO_AES_256_GCM, $key,
                OPENSSL_RAW_DATA, $iv, $authTag, $aad);

            if (false === $plaintext) {
                throw new UnexpectedValueException('Decrypting the input $ciphertext failed, please checking your $key and $iv whether or nor correct.');
            }

            return $plaintext;
        } else {
            // Use spomky-labs/php-aes-gcm for PHP 5.6+ compatibility
            return self::decryptWithSpomkyGcm($ciphertext, $key, $iv, $aad);
        }
    }

    /**
     * GCM encryption implementation using spomky-labs/php-aes-gcm for PHP 5.6+ compatibility
     *
     * @param string $plaintext
     * @param string $key
     * @param string $iv
     * @param string $aad
     * @return string
     */
    private static function encryptWithSpomkyGcm($plaintext, $key, $iv, $aad)
    {
        // For GCM, IV should be 12 bytes, but we need to handle different lengths
        if (strlen($iv) < 12) {
            // Pad IV to 12 bytes if it's shorter
            $iv = str_pad($iv, 12, "\0");
        } elseif (strlen($iv) > 12) {
            // Truncate IV to 12 bytes if it's longer
            $iv = substr($iv, 0, 12);
        }
        
        try {
            // Use spomky-labs/php-aes-gcm for encryption
            $result = \AESGCM\AESGCM::encrypt($key, $iv, $plaintext, $aad, 128);
            $ciphertext = $result[0];
            $authTag = $result[1];
            
            return base64_encode($ciphertext . $authTag);
        } catch (\Exception $e) {
            throw new UnexpectedValueException('Encrypting the input $plaintext failed: ' . $e->getMessage());
        }
    }

    /**
     * GCM decryption implementation using spomky-labs/php-aes-gcm for PHP 5.6+ compatibility
     *
     * @param string $ciphertext
     * @param string $key
     * @param string $iv
     * @param string $aad
     * @return string
     */
    private static function decryptWithSpomkyGcm($ciphertext, $key, $iv, $aad)
    {
        $ciphertext = base64_decode($ciphertext);
        
        // For GCM, IV should be 12 bytes, but we need to handle different lengths
        if (strlen($iv) < 12) {
            // Pad IV to 12 bytes if it's shorter
            $iv = str_pad($iv, 12, "\0");
        } elseif (strlen($iv) > 12) {
            // Truncate IV to 12 bytes if it's longer
            $iv = substr($iv, 0, 12);
        }
        
        // Extract auth tag (last 16 bytes) and actual ciphertext
        $authTag = substr($ciphertext, -16);
        $actualCiphertext = substr($ciphertext, 0, -16);
        
        try {
            // Use spomky-labs/php-aes-gcm for decryption
            $plaintext = \AESGCM\AESGCM::decrypt($key, $iv, $actualCiphertext, $aad, $authTag);
            
            if (false === $plaintext) {
                throw new UnexpectedValueException('Decrypting the input $ciphertext failed, please checking your $key and $iv whether or nor correct.');
            }
            
            return $plaintext;
        } catch (\Exception $e) {
            throw new UnexpectedValueException('Decrypting the input $ciphertext failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate GCM authentication tag using HMAC-SHA256
     *
     * @param string $ciphertext
     * @param string $aad
     * @param string $iv
     * @param string $key
     * @return string
     */
    private static function generateGcmTag($ciphertext, $aad, $iv, $key)
    {
        // Create authentication data: AAD + IV + ciphertext length
        $authData = $aad.$iv.pack('N', strlen($ciphertext));

        // Generate HMAC tag
        $tag = hash_hmac('sha256', $authData.$ciphertext, $key, true);

        // Return first 16 bytes as GCM tag
        return substr($tag, 0, static::BLOCK_SIZE);
    }

    /**
     * Secure string comparison to prevent timing attacks
     *
     * @param string $a
     * @param string $b
     * @return bool
     */
    private static function secureCompare($a, $b)
    {
        if (function_exists('hash_equals')) {
            return hash_equals($a, $b);
        }

        // Fallback for PHP < 5.6
        if (strlen($a) !== strlen($b)) {
            return false;
        }

        $result = 0;
        for ($i = 0; $i < strlen($a); $i++) {
            $result |= ord($a[$i]) ^ ord($b[$i]);
        }

        return $result === 0;
    }
}
