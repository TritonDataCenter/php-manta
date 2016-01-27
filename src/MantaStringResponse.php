<?php namespace Joyent\Manta;

/**
 * Class that takes in a string value and a headers value and wraps both
 * properties around an array interface. The string value is accessible via
 * the toString method allowing us to interact with the object as if it was a
 * string.
 *
 * This class allows us to simplify our return data type such that it can
 * be consumed as a string in most cases while still allowing the consumer of
 * the library access the headers as needed.
 *
 * @package Joyent\Manta
 */
class MantaStringResponse implements MantaResponse, \ArrayAccess
{
    /** @var string string value of response */
    protected $string;
    /** @var array headers returned as part of response */
    protected $headers;

    /**
     * Create a new instance of the response object with the specified string
     * and response headers.
     *
     * @param string $string string to wrap
     * @param array $headers headers to include
     */
    public function __construct($string, array $headers)
    {
        assert(is_string($string));
        $this->string = $string;
        $this->headers = $headers;
    }

    /**
     * Converts this object to a string when the object is used in any
     * string context.
     *
     * @return string
     * @link http://www.php.net/manual/en/language.oop5.magic.php#object.tostring
     */
    public function __toString()
    {
        return $this->string;
    }

    /**
     * Returns the HTTP headers associated with the response.
     *
     * @return array HTTP headers
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    public function offsetExists($offset)
    {
        return $offset == 'data' || $offset == 'headers';
    }

    public function offsetGet($offset)
    {
        if ($offset == 'data') {
            return $this->string;
        } elseif ($offset == 'headers') {
            return $this->headers;
        }

        return null;
    }

    public function offsetSet($offset, $value)
    {
        // do nothing
    }

    public function offsetUnset($offset)
    {
        // do nothing
    }
}
