<?php namespace Joyent\Manta;

/**
 * Class that takes in an object value and a headers value and wraps both
 * properties around arbitrary object's interface.
 *
 * This class allows us to simplify our return data type such that it can
 * be consumed as an object in most cases while still allowing the consumer of
 * the library access the headers as needed.
 *
 * @package Joyent\Manta
 */
class MantaObjectResponse extends \stdClass implements MantaResponse
{
    /** @var array headers returned as part of response */
    protected $headers;
    /** @var object object being wrapped */
    protected $object;

    /**
     * Create a new instance of the response object with the specified object
     * and response headers.
     *
     * @param object $object object to wrap
     * @param array $headers headers to include
     */
    public function __construct($object, array $headers)
    {
        $this->headers = $headers;
        $this->object = $object;
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

    /**
     * @return object that was wrapped
     */
    public function getWrappedObject()
    {
        return $this->object;
    }

    public function __call($method, $args)
    {
        // wrap all calls to this object
        return call_user_func_array(array($this->object, $method), $args);
    }

    public function __get($name)
    {
        return $this->object->{$name};
    }

    public function __set($name, $value)
    {
        $this->object->{$name} = $value;
    }

    public function __toString()
    {
        return (string)$this->object;
    }
}
