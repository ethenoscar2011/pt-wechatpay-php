<?php

namespace PtWeChatPay;

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\MessageInterface;
use function array_replace_recursive;
use function call_user_func;
use function constant;
use function defined;
use function implode;
use function php_uname;
use function sprintf;
use function strcasecmp;
use function strncasecmp;
use function substr;
use const PHP_OS;
use const PHP_VERSION;

// use GuzzleHttp\UriTemplate\UriTemplate; // Removed dependency for PHP 5.6 compatibility

/**
 * Decorate the `GuzzleHttp\Client` instance
 */
final class ClientDecorator implements ClientDecoratorInterface
{
    use ClientXmlTrait;
    use ClientJsonTrait;

    /**
     * @var ClientInterface - The APIv2's `\GuzzleHttp\Client`
     */
    protected $v2;

    /**
     * @var ClientInterface - The APIv3's `\GuzzleHttp\Client`
     */
    protected $v3;

    /**
     * Deep merge the input with the defaults
     *
     * @param array $config - The configuration.
     *
     * @return array - With the built-in configuration.
     */
    protected static function withDefaults()
    {
        $configs = func_get_args();
        $result = array_replace_recursive(static::$defaults, array('headers' => static::userAgent()));
        foreach ($configs as $config) {
            $result = array_replace_recursive($result, $config);
        }

        return $result;
    }

    /**
     * Prepare the `User-Agent` value key/value pair
     *
     * @return array
     */
    protected static function userAgent()
    {
        $clientVersion = defined(ClientInterface::class.'::VERSION') ? constant(ClientInterface::class.'::VERSION') : constant(ClientInterface::class.'::MAJOR_VERSION');
        $curlVersion = call_user_func('\curl_version');
        $curlVersion = is_array($curlVersion) ? $curlVersion['version'] : 'unknown';

        return array(
            'User-Agent' => implode(' ', array(
                sprintf('wechatpay-php/%s', static::VERSION),
                sprintf('GuzzleHttp/%s', $clientVersion),
                sprintf('curl/%s', $curlVersion),
                sprintf('(%s/%s)', PHP_OS, php_uname('r')),
                sprintf('PHP/%s', PHP_VERSION),
            )),
        );
    }

    /**
     * Taken body string
     *
     * @param MessageInterface $message - The message
     */
    protected static function body(MessageInterface $message)
    {
        $stream = $message->getBody();
        $content = (string)$stream;

        $stream->tell() && $stream->rewind();

        return $content;
    }

    /**
     * Decorate the `GuzzleHttp\Client` factory
     *
     * Acceptable \$config parameters stucture
     *   - mchid: string - The merchant ID
     *   - serial: string - The serial number of the merchant certificate
     *   - privateKey: mixed - The merchant private key.
     *   - certs: array - The wechatpay platform serial and certificate(s), `[$serial => $cert]` pair
     *   - secret?: string - The secret key string (optional)
     *   - merchant?: array - The merchant private key and certificate array. (optional)
     *
     * @param array $config - `\GuzzleHttp\Client`, `APIv3` and `APIv2` configuration settings.
     */
    public function __construct(array $config = array())
    {
        $this->{static::XML_BASED} = static::xmlBased($config);
        $this->{static::JSON_BASED} = static::jsonBased($config);
    }

    /**
     * Identify the `protocol` and `uri`
     *
     * @param string $uri - The uri string.
     *
     * @return array - the first element is the API version aka `protocol`, the second is the real `uri`
     */
    private static function prepare($uri)
    {
        return $uri && 0 === strncasecmp(static::XML_BASED.'/', $uri, 3)
            ? array(static::XML_BASED, substr($uri, 3))
            : array(static::JSON_BASED, $uri);
    }

    /**
     * @inheritDoc
     */
    public function select($protocol = null)
    {
        return $protocol && 0 === strcasecmp(static::XML_BASED, $protocol)
            ? $this->{static::XML_BASED}
            : $this->{static::JSON_BASED};
    }

    /**
     * Simple URI template expander for PHP 5.6 compatibility
     * Replaces {variable} with values from options array
     *
     * @param string $uri URI template
     * @param array $options Variables to replace
     * @return string Expanded URI
     */
    private static function expandUriTemplate($uri, array $options)
    {
        return preg_replace_callback('/\{([^}]+)\}/', function ($matches) use ($options) {
            $varName = $matches[1];

            return isset($options[$varName]) ? $options[$varName] : $matches[0];
        }, $uri);
    }

    /**
     * @inheritDoc
     */
    public function request($method, $uri, array $options = array())
    {
        $prepared = self::prepare(self::expandUriTemplate($uri, $options));
        $protocol = $prepared[0];
        $pathname = $prepared[1];

        return $this->select($protocol)->request($method, $pathname, $options);
    }

    /**
     * @inheritDoc
     */
    public function requestAsync($method, $uri, array $options = array())
    {
        $prepared = self::prepare(self::expandUriTemplate($uri, $options));
        $protocol = $prepared[0];
        $pathname = $prepared[1];

        return $this->select($protocol)->requestAsync($method, $pathname, $options);
    }
}
