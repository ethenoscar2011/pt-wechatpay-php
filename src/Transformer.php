<?php

namespace PtWeChatPay;

use SimpleXMLElement;
use Traversable;
use XMLWriter;
use function array_walk;
use function is_array;
use function is_object;
use function is_string;
use function libxml_clear_errors;
use function libxml_disable_entity_loader;
use function libxml_get_last_error;
use function libxml_use_internal_errors;
use function preg_match;
use function preg_replace;
use function simplexml_load_string;
use function sprintf;
use function strpos;
use function trigger_error;
use const LIBXML_COMPACT;
use const LIBXML_NOBLANKS;
use const LIBXML_NOCDATA;
use const LIBXML_NONET;
use const LIBXML_VERSION;

/**
 * Transform the `XML` to `Array` or `Array` to `XML`.
 */
class Transformer
{
    /**
     * Convert the $xml string to array.
     *
     * Always issue the `additional Libxml parameters` asof `LIBXML_NONET`
     *                                                    | `LIBXML_COMPACT`
     *                                                    | `LIBXML_NOCDATA`
     *                                                    | `LIBXML_NOBLANKS`
     *
     * @param string $xml - The xml string, default is `<xml/>` string
     *
     * @return array
     */
    public static function toArray($xml = '<xml/>')
    {
        if (LIBXML_VERSION < 20900) {
            $previous = libxml_disable_entity_loader(true);
        }

        libxml_use_internal_errors(true);
        $el = simplexml_load_string(static::sanitize($xml), 'SimpleXMLElement',
            LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOCDATA | LIBXML_NOBLANKS);

        if (LIBXML_VERSION < 20900 && isset($previous)) {
            libxml_disable_entity_loader($previous);
        }

        if (false === $el) {
            // while parsing failed, let's clean the internal buffer and
            // only leave the last error message which still can be fetched by the `error_get_last()` function.
            $err = libxml_get_last_error();
            if (false !== $err) {
                libxml_clear_errors();
                @trigger_error(sprintf(
                    'Parsing the $xml failed with the last error(level=%d,code=%d,message=%s).',
                    $err->level, $err->code, $err->message
                ));
            }

            return array();
        }

        return static::cast($el);
    }

    /**
     * Recursive cast the $thing as array data structure.
     *
     * @param array|object|\SimpleXMLElement $thing - The thing
     *
     * @return array
     */
    protected static function cast($thing)
    {
        $data = (array)$thing;
        array_walk($data, function (&$value) {
            static::value($value);
        });

        return $data;
    }

    /**
     * Cast the value $thing, specially doing the `array`, `object`, `SimpleXMLElement` to `array`
     *
     * @param mixed $thing - The value thing reference
     */
    protected static function value(&$thing)
    {
        if (is_array($thing)) {
            $thing = static::cast($thing);
        }
        if (is_object($thing) && $thing instanceof SimpleXMLElement) {
            $thing = $thing->count() ? static::cast($thing) : (string)$thing;
        }
    }

    /**
     * Trim invalid characters from the $xml string
     *
     * @see https://github.com/w7corp/easywechat/pull/1419
     * @license https://github.com/w7corp/easywechat/blob/4.x/LICENSE
     *
     * @param string $xml - The xml string
     */
    public static function sanitize($xml)
    {
        $result = preg_replace('#[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]+#u', '', $xml);

        return $result !== null ? $result : '';
    }

    /**
     * Transform the given $data array as of an XML string.
     *
     * @param array $data - The data array
     * @param boolean $headless - The headless flag, default `true` means without the `<?xml version="1.0" encoding="UTF-8" ?>` doctype
     * @param boolean $indent - Toggle indentation on/off, default is `false` off
     * @param string $root - The root node label, default is `xml` string
     * @param string $item - The nest array identify text, default is `item` string
     *
     * @return string - The xml string
     */
    public static function toXml(array $data, $headless = true, $indent = false, $root = 'xml', $item = 'item')
    {
        $writer = new XMLWriter();
        $writer->openMemory();
        $writer->setIndent($indent);
        if (!$headless) {
            $writer->startDocument('1.0', 'utf-8');
        }
        $writer->startElement($root);
        static::walk($writer, $data, $item);
        $writer->endElement();
        if (!$headless) {
            $writer->endDocument();
        }
        $xml = $writer->outputMemory();
        $writer = null;

        return $xml;
    }

    /**
     * Walk the given data array by the `XMLWriter` instance.
     *
     * @param \XMLWriter $writer - The `XMLWriter` instance reference
     * @param array $data - The data array
     * @param string $item - The nest array identify tag text
     */
    protected static function walk(XMLWriter &$writer, array $data, $item)
    {
        foreach ($data as $key => $value) {
            $tag = is_string($key) && static::isElementNameValid($key) ? $key : $item;
            $writer->startElement($tag);
            if (is_array($value) || (is_object($value) && $value instanceof Traversable)) {
                static::walk($writer, (array)$value, $item);
            } else {
                static::content($writer, (string)$value);
            }
            $writer->endElement();
        }
    }

    /**
     * Write content text.
     *
     * The content text includes the characters `<`, `>`, `&` and `"` are written as CDATA references.
     * All others including `'` are written literally.
     *
     * @param \XMLWriter $writer - The `XMLWriter` instance reference
     * @param string $thing - The content text
     */
    protected static function content(XMLWriter &$writer, $thing = '')
    {
        if (static::needsCdataWrapping($thing)) {
            $writer->writeCdata($thing);
        } else {
            $writer->text($thing);
        }
    }

    /**
     * Checks the name is a valid xml element name.
     *
     * @param string $name - The name
     *
     * @return boolean - True means valid
     * @see \Symfony\Component\Serializer\Encoder\XmlEncoder::isElementNameValid
     * @license https://github.com/symfony/serializer/blob/5.3/LICENSE
     *
     */
    protected static function isElementNameValid($name = '')
    {
        return $name && false === strpos($name, ' ') && preg_match('#^[\pL_][\pL0-9._:-]*$#ui', $name);
    }

    /**
     * Checks if a value contains any characters which would require CDATA wrapping.
     *
     * Notes here: the `XMLWriter` shall been wrapped the `"` string as `&quot;` symbol string,
     *             it's strictly following the `XMLWriter` specification here.
     *
     * @param string $value - The value
     *
     * @return boolean - True means need
     * @see \Symfony\Component\Serializer\Encoder\XmlEncoder::needsCdataWrapping
     * @license https://github.com/symfony/serializer/blob/5.3/LICENSE
     *
     */
    protected static function needsCdataWrapping($value = '')
    {
        return $value && 0 < preg_match('#[>&"<]#', $value);
    }
}
