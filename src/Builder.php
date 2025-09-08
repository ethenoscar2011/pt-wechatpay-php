<?php

namespace PtWeChatPay;

use ArrayIterator;
use function array_filter;
use function implode;
use function preg_replace_callback_array;
use function strtolower;

/**
 * Chainable the client for sending HTTP requests.
 */
final class Builder
{
    /**
     * Building & decorate the chainable `GuzzleHttp\Client`
     *
     * Minimum mandatory \$config parameters structure
     *   - mchid: string - The merchant ID
     *   - serial: string - The serial number of the merchant certificate
     *   - privateKey: \OpenSSLAsymmetricKey|\OpenSSLCertificate|object|resource|string - The merchant private key.
     *   - certs: array<string, \OpenSSLAsymmetricKey|\OpenSSLCertificate|object|resource|string> - The wechatpay platform serial and certificate(s), `[$serial => $cert]` pair
     *   - secret?: string - The secret key string (optional)
     *   - merchant?: array{key?: string, cert?: string} - The merchant private key and certificate array. (optional)
     *   - merchant<?key, string|string[]> - The merchant private key(file path string). (optional)
     *   - merchant<?cert, string|string[]> - The merchant certificate(file path string). (optional)
     *
     * ```php
     * // usage samples
     * $instance = Builder::factory([]);
     * $res = $instance->chain('v3/merchantService/complaintsV2')->get(['debug' => true]);
     * $res = $instance->chain('v3/merchant-service/complaint-notifications')->get(['debug' => true]);
     * $instance->v3->merchantService->ComplaintNotifications->postAsync([])->wait();
     * $instance->v3->certificates->getAsync()->then(function() {})->otherwise(function() {})->wait();
     * ```
     *
     * @param array $config - `\GuzzleHttp\Client`, `APIv3` and `APIv2` configuration settings.
     */
    public static function factory(array $config = array())
    {
        return new BuilderChainableImpl(array(), new ClientDecorator($config));
    }

    private function __construct()
    {
        // cannot be instantiated
    }
}

/**
 * Implementation of BuilderChainable interface
 */
class BuilderChainableImpl extends ArrayIterator implements BuilderChainable
{
    use BuilderTrait;

    /**
     * Compose the chainable `ClientDecorator` instance, most starter with the tree root point
     * @param array $input
     * @param ClientDecoratorInterface $instance
     */
    public function __construct(array $input = array(), ClientDecoratorInterface $instance = null)
    {
        parent::__construct($input, self::STD_PROP_LIST | self::ARRAY_AS_PROPS);

        $this->setDriver($instance);
    }

    /**
     * @var ClientDecoratorInterface $driver - The `ClientDecorator` instance
     */
    protected $driver;

    /**
     * `$driver` setter
     * @param ClientDecoratorInterface $instance - The `ClientDecorator` instance
     */
    public function setDriver(ClientDecoratorInterface $instance)
    {
        $this->driver = $instance;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getDriver()
    {
        return $this->driver;
    }

    /**
     * Normalize the `$thing` by the rules: `PascalCase` -> `camelCase`
     *                                    & `camelCase` -> `kebab-case`
     *                                    & `_placeholder_` -> `{placeholder}`
     *
     * @param string $thing - The string waiting for normalization
     *
     * @return string
     */
    protected function normalize($thing = '')
    {
        if (function_exists('preg_replace_callback_array')) {
            return preg_replace_callback_array(array(
                '#^[A-Z]#' => function ($piece) {
                    return strtolower($piece[0]);
                },
                '#[A-Z]#' => function ($piece) {
                    return '-'.strtolower($piece[0]);
                },
                '#^_(.*)_$#' => function ($piece) {
                    return '{'.$piece[1].'}';
                },
            ), $thing) ?: $thing;
        } else {
            // Fallback for PHP 5.6
            $result = $thing;
            $result = preg_replace_callback('#^[A-Z]#', function ($piece) {
                return strtolower($piece[0]);
            }, $result);
            $result = preg_replace_callback('#[A-Z]#', function ($piece) {
                return '-'.strtolower($piece[0]);
            }, $result);
            $result = preg_replace_callback('#^_(.*)_$#', function ($piece) {
                return '{'.$piece[1].'}';
            }, $result);

            return $result;
        }
    }

    /**
     * URI pathname
     *
     * @param string $seperator - The URI seperator, default is slash(`/`) character
     *
     * @return string - The URI string
     */
    protected function pathname($seperator = '/')
    {
        return implode($seperator, $this->simplized());
    }

    /**
     * Only retrieve a copy array of the URI segments
     *
     * @return array - The URI segments array
     */
    protected function simplized()
    {
        return array_filter($this->getArrayCopy(), function ($v) {
            return !($v instanceof BuilderChainable);
        });
    }

    /**
     * @inheritDoc
     */
    public function offsetGet($key)
    {
        if (!$this->offsetExists($key)) {
            $indices = $this->simplized();
            $indices[] = $this->normalize($key);
            $this->offsetSet($key, new self($indices, $this->getDriver()));
        }

        return parent::offsetGet($key);
    }

    /**
     * @inheritDoc
     */
    public function chain($segment)
    {
        return $this->offsetGet($segment);
    }
}
