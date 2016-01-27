<?php namespace Joyent\Manta;

use Psr\Http\Message\StreamInterface;

/**
 * Class that takes in an stream value and a headers value and wraps both
 * properties around arbitrary object's interface.
 *
 * This class allows us to simplify our return data type such that it can
 * be consumed as an stream in most cases while still allowing the consumer of
 * the library access the headers as needed.
 *
 * @package Joyent\Manta
 */
class MantaStreamResponse extends MantaObjectResponse implements StreamInterface
{
    /**
     * Creates a new instance of a HTTP response object that wraps StreamInterface.
     * @param StreamInterface $stream StreamInterface object
     * @param array $headers HTTP response headers
     */
    public function __construct($stream, array $headers)
    {
        assert(is_a($stream, 'Psr\Http\Message\StreamInterface'));
        parent::__construct($stream, $headers);
    }

    public function close()
    {
        return $this->object->close();
    }

    public function detach()
    {
        return $this->object->detach();
    }

    public function getSize()
    {
        return $this->object->getSize();
    }

    public function tell()
    {
        return $this->object->tell();
    }

    public function eof()
    {
        return $this->object->eof();
    }

    public function isSeekable()
    {
        return $this->object->isSeekable();
    }

    public function seek($offset, $whence = SEEK_SET)
    {
        return $this->object->seek($offset, $whence);
    }

    public function rewind()
    {
        return $this->object->rewind();
    }

    public function isWritable()
    {
        return $this->object->isWritable();
    }

    public function write($string)
    {
        return $this->object->write($string);
    }

    public function isReadable()
    {
        return $this->object->isReadable();
    }

    public function read($length)
    {
        return $this->object->read($length);
    }

    public function getContents()
    {
        return $this->object->getContents();
    }

    public function getMetadata($key = null)
    {
        return $this->object->getMetadata($key);
    }
}
