<?php

namespace PtWeChatPay\Tests\OpenAPI\V2\Pay;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\LazyOpenStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use PtWeChatPay\Builder;
use PtWeChatPay\ClientDecoratorInterface;
use PtWeChatPay\Transformer;
use function dirname;
use function substr_count;
use const DIRECTORY_SEPARATOR;

class DownloadbillTest extends TestCase
{
    private static $CSV_DATA_LINE_MAXIMUM_BYTES = 1024;
    private static $CSV_DATA_FIRST_BYTE = '`';
    private static $CSV_DATA_SEPERATOR = ',`';

    /** @var MockHandler $mock */
    private $mock;

    private function guzzleMockStack()
    {
        $this->mock = new MockHandler();

        return HandlerStack::create($this->mock);
    }

    /**
     * @param string $mchid
     * @return array
     */
    private function prepareEnvironment($mchid)
    {
        $instance = Builder::factory(array(
            'mchid' => $mchid,
            'serial' => 'nop',
            'privateKey' => 'any',
            'certs' => array('any' => null),
            'secret' => '',
            'handler' => $this->guzzleMockStack(),
        ));

        /** @var HandlerStack $stack */
        $stack = $instance->getDriver()->select(ClientDecoratorInterface::XML_BASED)->getConfig('handler');
        $stack = clone $stack;
        $stack->remove('transform_response');

        $endpoint = $instance->chain('v2/pay/downloadbill');

        return array($endpoint, $stack);
    }

    /**
     * @return array
     */
    public function mockRequestsDataProvider()
    {
        $mchid = '1230000109';
        $data = array(
            'return_code' => 'FAIL',
            'return_msg' => 'invalid reason',
            'error_code' => '20001',
        );
        $file = dirname(dirname(dirname(__DIR__))).DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'bill.ALL.csv';
        $stream = new LazyOpenStream($file, 'rb');

        $xmlDataStructure = array(
            'appid' => 'wx8888888888888888',
            'mch_id' => $mchid,
            'bill_type' => 'ALL',
            'bill_date' => '20140603',
        );

        return array(
            'return_code=FAIL' => array(
                $mchid,
                $xmlDataStructure,
                new Response(200, array(), Transformer::toXml($data)),
            ),
            'CSV stream' => array($mchid, $xmlDataStructure, new Response(200, array(), $stream)),
        );
    }

    /**
     * @dataProvider mockRequestsDataProvider
     * @param string $mchid
     * @param array $data
     * @param ResponseInterface $respondor
     */
    public function testPost($mchid, array $data, ResponseInterface $respondor)
    {
        list($endpoint, $stack) = $this->prepareEnvironment($mchid);

        $this->mock->reset();
        $this->mock->append($respondor);

        $res = $endpoint->post(array(
            'handler' => $stack,
            'xml' => $data,
        ));
        self::responseAssertion($res);

        $this->mock->reset();
        $this->mock->append($respondor);

        $res = $endpoint->post(array('xml' => $data));
        self::responseAssertion($res);
    }

    /**
     * @param ResponseInterface $response
     * @param boolean $testFinished
     */
    private static function responseAssertion(ResponseInterface $response, $testFinished = false)
    {
        $stream = $response->getBody();
        $stream->tell() && $stream->rewind();
        $firstFiveBytes = $stream->read(5);
        $stream->rewind();
        if ('<xml>' === $firstFiveBytes) {
            $txt = (string)$stream;
            $array = Transformer::toArray($txt);
            static::assertArrayHasKey('return_msg', $array);
            static::assertArrayHasKey('return_code', $array);
            static::assertArrayHasKey('error_code', $array);
        } else {
            $line = Utils::readLine($stream, self::$CSV_DATA_LINE_MAXIMUM_BYTES);
            $headerCommaCount = substr_count($line, ',');
            $isRecord = false;
            do {
                $line = Utils::readLine($stream, self::$CSV_DATA_LINE_MAXIMUM_BYTES);
                $isRecord = $line[0] === self::$CSV_DATA_FIRST_BYTE;
                if ($isRecord) {
                    static::assertEquals($headerCommaCount, substr_count($line, self::$CSV_DATA_SEPERATOR));
                }
            } while (!$stream->eof() && $isRecord);
            $summaryCommaCount = substr_count($line, ',');
            $line = Utils::readLine($stream, self::$CSV_DATA_LINE_MAXIMUM_BYTES);
            static::assertTrue($line[0] === self::$CSV_DATA_FIRST_BYTE);
            static::assertEquals($summaryCommaCount, substr_count($line, self::$CSV_DATA_SEPERATOR));
            $stream->rewind();
            if ($testFinished) {
                $stream->close();
                static::assertFalse($stream->isSeekable());
            }
        }
    }

    /**
     * @dataProvider mockRequestsDataProvider
     * @param string $mchid
     * @param array $data
     * @param ResponseInterface $respondor
     */
    public function testPostAsync($mchid, array $data, ResponseInterface $respondor)
    {
        list($endpoint, $stack) = $this->prepareEnvironment($mchid);

        $this->mock->reset();
        $this->mock->append($respondor);

        $endpoint->postAsync(array(
            'xml' => $data,
        ))->then(function (ResponseInterface $response) {
            self::responseAssertion($response);
        })->wait();

        $this->mock->reset();
        $this->mock->append($respondor);

        $endpoint->postAsync(array(
            'handler' => $stack,
            'xml' => $data,
        ))->then(function (ResponseInterface $response) {
            self::responseAssertion($response, true);
        })->wait();
    }
}
