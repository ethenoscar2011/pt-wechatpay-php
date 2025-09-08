<?php

namespace PtWeChatPay\Util;

use GuzzleHttp\Psr7\BufferStream;
use GuzzleHttp\Psr7\CachingStream;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\LazyOpenStream;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use UnexpectedValueException;
use function basename;
use function json_encode;
use function sprintf;

/**
 * Util for Media(image, video or text/csv whose are the platform acceptable file types etc) uploading.
 */
class MediaUtil
{
    /**
     * @var string - local file path
     */
    private $filepath;

    /**
     * @var StreamInterface|null - The `file` stream
     */
    private $fileStream;

    /**
     * @var StreamInterface - The `meta` stream
     */
    private $metaStream;

    /**
     * @var MultipartStream - The `multipart/form-data` stream
     */
    private $multipart;

    /**
     * @var StreamInterface - multipart stream wrapper
     */
    private $stream;

    /**
     * Constructor
     *
     * @param string $filepath The media file path or file name,
     *                         should be one of the
     *                         images(jpg|bmp|png)
     *                         or
     *                         video(avi|wmv|mpeg|mp4|mov|mkv|flv|f4v|m4v|rmvb)
     *                         or
     *                         text/csv whose are the platform acceptable etc.
     * @param StreamInterface|null $fileStream File content stream, optional
     */
    public function __construct($filepath, StreamInterface $fileStream = null)
    {
        $this->filepath = $filepath;
        $this->fileStream = $fileStream;
        $this->composeStream();
    }

    /**
     * Compose the GuzzleHttp\Psr7\FnStream
     */
    private function composeStream()
    {
        $basename = basename($this->filepath);
        $stream = $this->fileStream ?: new LazyOpenStream($this->filepath, 'rb');
        if ($stream instanceof StreamInterface && !($stream->isSeekable())) {
            $stream = new CachingStream($stream);
        }
        if (!($stream instanceof StreamInterface)) {
            throw new UnexpectedValueException(sprintf('Cannot open or caching the file: `%s`', $this->filepath));
        }

        $buffer = new BufferStream();
        $metaStream = FnStream::decorate($buffer, array(
            'getSize' => function () {
                return null;
            },
            // The `BufferStream` doen't have `uri` metadata(`null` returned),
            // but the `MultipartStream` did checked this prop with the `substr` method, which method described
            // the first paramter must be the string on the `strict_types` mode.
            // Decorate the `getMetadata` for this case.
            'getMetadata' => function ($key = null) use ($buffer) {
                if ('uri' === $key) {
                    return 'php://temp';
                }

                return $buffer->getMetadata($key);
            },
        ));

        $this->fileStream = $this->fileStream ?: $stream;
        $this->metaStream = $metaStream;

        $this->setMeta();

        $multipart = new MultipartStream(array(
            array(
                'name' => 'meta',
                'contents' => $this->metaStream,
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
            ),
            array(
                'name' => 'file',
                'filename' => $basename,
                'contents' => $this->fileStream,
            ),
        ));
        $this->multipart = $multipart;

        $this->stream = FnStream::decorate($multipart, array(
            '__toString' => function () {
                return $this->getMeta();
            },
            'getSize' => function () {
                return null;
            },
        ));
    }

    /**
     * Set the `meta` part of the `multipart/form-data` stream
     *
     * Note: The `meta` weren't be the `media file`'s `meta data` anymore.
     *
     *       Previous whose were designed as `{filename,sha256}`,
     *       but another API was described asof `{bank_type,filename,sha256}`.
     *
     *       Exposed the ability of setting the `meta` for the `new` data structure.
     *
     * @param string|null $json - The `meta` string
     * @since v1.3.2
     */
    public function setMeta($json = null)
    {
        $content = $json ?: (string)json_encode(array(
            'filename' => basename($this->filepath),
            'sha256' => $this->fileStream ? Utils::hash($this->fileStream, 'sha256') : '',
        ));
        // clean the metaStream's buffer string
        $this->metaStream->getContents();

        return $this->metaStream->write($content);
    }

    /**
     * Get the `meta` string
     */
    public function getMeta()
    {
        $json = (string)$this->metaStream;
        $this->setMeta($json);

        return $json;
    }

    /**
     * Get the `FnStream` which is the `MultipartStream` decorator
     */
    public function getStream()
    {
        return $this->stream;
    }

    /**
     * Get the `Content-Type` value from the `{$this->multipart}` instance
     */
    public function getContentType()
    {
        return 'multipart/form-data; boundary='.$this->multipart->getBoundary();
    }
}
