<?php namespace Joyent\Manta;

/**
 * Class that takes in an array value and a headers value and wraps both
 * properties around an array interface.
 *
 * This class allows us to simplify our return data type such that it can
 * be consumed as an array in most cases while still allowing the consumer of
 * the library access the headers as needed.
 *
 * @package Joyent\Manta
 */
class MantaArrayResponse extends \ArrayObject implements MantaResponse
{
    /** @var array headers returned as part of response */
    protected $headers;

    /**
     * Create a new instance of the response object with the specified array
     * and response headers.
     *
     * @param array $array array to wrap
     * @param array $headers headers to include
     */
    public function __construct(array $array, array $headers)
    {
        $this->headers = $headers;
        parent::__construct($array);
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
}
