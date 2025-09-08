#!/usr/bin/env php
<?php

// load autoload.php
$possibleFiles = array(
    __DIR__.'/../vendor/autoload.php',
    __DIR__.'/../../../autoload.php',
    __DIR__.'/../../autoload.php',
);
$file = null;
foreach ($possibleFiles as $possibleFile) {
    if (file_exists($possibleFile)) {
        $file = $possibleFile;
        break;
    }
}
if (null === $file) {
    throw new RuntimeException('Unable to locate autoload.php file.');
}

require_once $file;
unset($possibleFiles, $possibleFile, $file);

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Middleware;
use Psr\Http\Message\ResponseInterface;
use PtWeChatPay\Builder;
use PtWeChatPay\ClientDecoratorInterface;
use PtWeChatPay\Crypto\AesGcm;

/**
 * CertificateDownloader class
 */
class CertificateDownloader
{
    const DEFAULT_BASE_URI = 'https://api.mch.weixin.qq.com/';

    public function run()
    {
        $opts = $this->parseOpts();

        if (!$opts || isset($opts['help'])) {
            $this->printHelp();

            return;
        }
        if (isset($opts['version'])) {
            self::prompt(ClientDecoratorInterface::VERSION);

            return;
        }
        $this->job($opts);
    }

    /**
     * Before `verifier` executing, decrypt and put the platform certificate(s) into the `$certs` reference.
     *
     * @param string $apiv3Key
     * @param array $certs
     *
     * @return callable
     */
    private static function certsInjector($apiv3Key, array &$certs)
    {
        return function (ResponseInterface $response) use ($apiv3Key, &$certs) {
            $body = (string)$response->getBody();
            $json = json_decode($body);
            $data = is_object($json) && isset($json->data) && is_array($json->data) ? $json->data : array();
            array_map(function ($row) use ($apiv3Key, &$certs) {
                $cert = $row->encrypt_certificate;
                $certs[$row->serial_no] = AesGcm::decrypt($cert->ciphertext, $apiv3Key, $cert->nonce,
                    $cert->associated_data);
            }, $data);

            return $response;
        };
    }

    /**
     * @param array $opts
     *
     * @return void
     */
    private function job(array $opts)
    {
        static $certs = array('any' => null);

        $outputDir = isset($opts['output']) ? $opts['output'] : sys_get_temp_dir();
        $apiv3Key = (string)$opts['key'];

        $instance = Builder::factory(array(
            'mchid' => $opts['mchid'],
            'serial' => $opts['serialno'],
            'privateKey' => file_get_contents((string)$opts['privatekey']),
            'certs' => &$certs,
            'base_uri' => (string)(isset($opts['baseuri']) ? $opts['baseuri'] : self::DEFAULT_BASE_URI),
        ));

        /** @var \GuzzleHttp\HandlerStack $stack */
        $stack = $instance->getDriver()->select(ClientDecoratorInterface::JSON_BASED)->getConfig('handler');
        // The response middle stacks were executed one by one on `FILO` order.
        $stack->after('verifier', Middleware::mapResponse(self::certsInjector($apiv3Key, $certs)), 'injector');
        $stack->before('verifier', Middleware::mapResponse(self::certsRecorder((string)$outputDir, $certs)),
            'recorder');

        $instance->chain('v3/certificates')->getAsync(
            array('debug' => true)
        )->otherwise(function ($exception) {
            self::prompt($exception->getMessage());
            if ($exception instanceof RequestException && $exception->hasResponse()) {
                /** @var ResponseInterface $response */
                $response = $exception->getResponse();
                self::prompt((string)$response->getBody(), '', '');
            }
            self::prompt($exception->getTraceAsString());
        })->wait();
    }

    /**
     * After `verifier` executed, wrote the platform certificate(s) onto disk.
     *
     * @param string $outputDir
     * @param array $certs
     *
     * @return callable
     */
    private static function certsRecorder($outputDir, array &$certs)
    {
        return function (ResponseInterface $response) use ($outputDir, &$certs) {
            $body = (string)$response->getBody();
            $json = json_decode($body);
            $data = is_object($json) && isset($json->data) && is_array($json->data) ? $json->data : array();
            array_walk($data, function ($row, $index) use ($outputDir, &$certs) {
                $serialNo = $row->serial_no;
                $outpath = $outputDir.DIRECTORY_SEPARATOR.'wechatpay_'.$serialNo.'.pem';

                self::prompt(
                    'Certificate #'.$index.' {',
                    '    Serial Number: '.self::highlight($serialNo),
                    '    Not Before: '.(new DateTime($row->effective_time))->format(DateTime::W3C),
                    '    Not After: '.(new DateTime($row->expire_time))->format(DateTime::W3C),
                    '    Saved to: '.self::highlight($outpath),
                    '    You may confirm the above infos again even if this library already did(by Crypto\Rsa::verify):',
                    '      '.self::highlight(sprintf('openssl x509 -in %s -noout -serial -dates', $outpath)),
                    '    Content: ', '', isset($certs[$serialNo]) ? $certs[$serialNo] : '', '',
                    '}'
                );

                file_put_contents($outpath, $certs[$serialNo]);
            });

            return $response;
        };
    }

    /**
     * @param string $thing
     */
    private static function highlight($thing)
    {
        return sprintf("\x1B[1;32m%s\x1B[0m", $thing);
    }

    /**
     * @param mixed $messages
     */
    private static function prompt()
    {
        $messages = func_get_args();
        array_walk($messages, function ($message) {
            printf('%s%s', $message, PHP_EOL);
        });
    }

    /**
     * @return array|null
     */
    private function parseOpts()
    {
        $opts = array(
            array('key', 'k', true),
            array('mchid', 'm', true),
            array('privatekey', 'f', true),
            array('serialno', 's', true),
            array('output', 'o', false),
            // baseuri can be one of 'https://api2.mch.weixin.qq.com/', 'https://apihk.mch.weixin.qq.com/'
            array('baseuri', 'u', false),
        );

        $shortopts = 'hV';
        $longopts = array('help', 'version');
        foreach ($opts as $opt) {
            $key = $opt[0];
            $alias = $opt[1];
            $shortopts .= $alias.':';
            $longopts[] = $key.':';
        }
        $parsed = getopt($shortopts, $longopts);

        if (!$parsed) {
            return null;
        }

        $args = array();
        foreach ($opts as $opt) {
            $key = $opt[0];
            $alias = $opt[1];
            $mandatory = $opt[2];
            if (isset($parsed[$key]) || isset($parsed[$alias])) {
                /** @var string|array $possible */
                $possible = isset($parsed[$key]) ? $parsed[$key] : (isset($parsed[$alias]) ? $parsed[$alias] : '');
                $args[$key] = is_array($possible) ? $possible[0] : $possible;
            } elseif ($mandatory) {
                return null;
            }
        }

        if (isset($parsed['h']) || isset($parsed['help'])) {
            $args['help'] = true;
        }
        if (isset($parsed['V']) || isset($parsed['version'])) {
            $args['version'] = true;
        }

        return $args;
    }

    private function printHelp()
    {
        self::prompt(
            'Usage: 微信支付平台证书下载工具 [-hV]',
            '                    -f=<privateKeyFilePath> -k=<apiv3Key> -m=<merchantId>',
            '                    -s=<serialNo> -o=[outputFilePath] -u=[baseUri]',
            'Options:',
            '  -m, --mchid=<merchantId>   商户号',
            '  -s, --serialno=<serialNo>  商户证书的序列号',
            '  -f, --privatekey=<privateKeyFilePath>',
            '                             商户的私钥文件',
            '  -k, --key=<apiv3Key>       APIv3密钥',
            '  -o, --output=[outputFilePath]',
            '                             下载成功后保存证书的路径，可选，默认为临时文件目录夹',
            '  -u, --baseuri=[baseUri]    接入点，可选，默认为 '.self::DEFAULT_BASE_URI,
            '  -V, --version              Print version information and exit.',
            '  -h, --help                 Show this help message and exit.', ''
        );
    }
}

// main
(new CertificateDownloader())->run();
