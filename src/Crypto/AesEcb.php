<?php

namespace PtWeChatPay\Crypto;

use UnexpectedValueException;
use function base64_decode;
use function base64_encode;
use function openssl_decrypt;
use function openssl_encrypt;
use const OPENSSL_RAW_DATA;

/**
 * Aes encrypt/decrypt using `aes-256-ecb` algorithm with pkcs7padding.
 */
class AesEcb implements AesInterface
{
    /**
     * @inheritDoc
     */
    public static function encrypt($plaintext, $key, $iv = '', $aad = '')
    {
        $ciphertext = openssl_encrypt($plaintext, static::ALGO_AES_256_ECB, $key, OPENSSL_RAW_DATA, $iv = '');

        if (false === $ciphertext) {
            throw new UnexpectedValueException('Encrypting the input $plaintext failed, please checking your $key and $iv whether or nor correct.');
        }

        return base64_encode($ciphertext);
    }

    /**
     * @inheritDoc
     */
    public static function decrypt($ciphertext, $key, $iv = '', $aad = '')
    {
        $plaintext = openssl_decrypt(base64_decode($ciphertext), static::ALGO_AES_256_ECB, $key, OPENSSL_RAW_DATA,
            $iv = '');

        if (false === $plaintext) {
            throw new UnexpectedValueException('Decrypting the input $ciphertext failed, please checking your $key and $iv whether or nor correct.');
        }

        return $plaintext;
    }
}
