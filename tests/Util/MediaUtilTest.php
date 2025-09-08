<?php

namespace PtWeChatPay\Tests\Util;

use GuzzleHttp\Psr7\LazyOpenStream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use PtWeChatPay\Util\MediaUtil;
use function base64_decode;
use function base64_encode;
use function dirname;
use function hash_file;
use function json_decode;
use function json_encode;
use function openssl_digest;
use const DIRECTORY_SEPARATOR;

class MediaUtilTest extends TestCase
{
    private static $ALGO_SHA256 = 'sha256';
    private static $FOPEN_MODE_BINARYREAD = 'rb';

    /**
     * @return array
     */
    public function fileDataProvider()
    {
        return array(
            'normal local file' => array(
                $logo = dirname(__DIR__).DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'logo.png',
                null,
                'logo.png',
                hash_file(self::$ALGO_SHA256, $logo) ?: '',
            ),
            'file:// protocol with local file' => array(
                'file://'.$logo,
                null,
                'logo.png',
                hash_file(self::$ALGO_SHA256, $logo) ?: '',
            ),
            'data:// protocol with base64 string' => array(//RFC2397
                'transparent.gif',
                new LazyOpenStream(
                    'data://image/gif;base64,'.($data = 'R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=='),
                    self::$FOPEN_MODE_BINARYREAD
                ),
                'transparent.gif',
                openssl_digest(base64_decode($data), self::$ALGO_SHA256) ?: '',
            ),
            'data://text/csv;base64 string' => array(//RFC2397
                'active_user_batch_tasks_001.csv',
                new LazyOpenStream(
                    'data://text/csv;base64,'.base64_encode($data = implode('\n', array(
                        'LQT_Wechatpay_Platform_Certificate_Encrypted_Line_One',
                        'LQT_Wechatpay_Platform_Certificate_Encrypted_Line_Two',
                    ))),
                    self::$FOPEN_MODE_BINARYREAD
                ),
                'active_user_batch_tasks_001.csv',
                openssl_digest($data, self::$ALGO_SHA256) ?: '',
            ),
        );
    }

    /**
     * @dataProvider fileDataProvider
     *
     * @param string $file
     * @param StreamInterface|null $stream
     * @param string $expectedFilename
     * @param string $expectedSha256Digest
     */
    public function testConstructor($file, $stream = null, $expectedFilename = '', $expectedSha256Digest = '')
    {
        $util = new MediaUtil($file, $stream);

        $this->assertInternalType('object', $util);
        $this->assertInternalType('string', $json = $util->getMeta());
        $this->assertJson($json);

        $data = (array)json_decode($json, true);
        $filename = $data['filename'];
        $digest = $data['sha256'];
        $this->assertEquals($expectedFilename, $filename);
        $this->assertEquals($expectedSha256Digest, $digest);

        $this->assertInstanceOf(StreamInterface::class, $util->getStream());
        $this->assertInstanceOf(\GuzzleHttp\Psr7\FnStream::class, $util->getStream());
        $this->assertEquals($json, (string)$util->getStream());
        $this->assertNull($util->getStream()->getSize());

        $this->assertInternalType('string', $util->getContentType());
        $this->assertStringStartsWith('multipart/form-data; boundary=', $util->getContentType());
    }

    /**
     * @dataProvider fileDataProvider
     *
     * @param string $file
     * @param StreamInterface|null $stream
     * @param string $expectedFilename
     * @param string $expectedSha256Digest
     */
    public function testSetMeta($file, $stream = null, $expectedFilename = '', $expectedSha256Digest = '')
    {
        $media = new MediaUtil($file, $stream);
        $json = $media->getMeta();
        $this->assertJson($json);

        /** @var array $array */
        $array = json_decode($json, true);
        $this->assertInternalType('array', $array);
        $this->assertArrayHasKey('filename', $array);
        $this->assertArrayHasKey('sha256', $array);
        $this->assertArrayNotHasKey('bank_type', $array);

        $filename = $array['filename'];
        $digest = $array['sha256'];
        $this->assertEquals($expectedFilename, $filename);
        $this->assertEquals($expectedSha256Digest, $digest);
        $this->assertEquals($json, (string)$media->getStream());

        $meta = json_encode(array('filename' => $filename, 'sha256' => $digest, 'bank_type' => 'LQT')) ?: null;
        $this->assertInternalType('integer', $media->setMeta($meta));

        $json = $media->getMeta();
        $this->assertJson($json);
        $this->assertEquals($meta, $json);
        $this->assertEquals($meta, (string)$media->getStream());
        $this->assertEquals($json, (string)$media->getStream());

        /** @var array $array */
        $array = json_decode((string)$media->getStream(), true);
        $this->assertInternalType('array', $array);
        $this->assertArrayHasKey('filename', $array);
        $this->assertArrayHasKey('sha256', $array);
        $this->assertArrayHasKey('bank_type', $array);
    }
}
