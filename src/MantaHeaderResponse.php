<?php namespace Joyent\Manta;

/**
 * Class that represents the response from the Manta API when only HTTP response
 * headers are available.
 *
 * @package Joyent\Manta
 */
class MantaHeaderResponse implements MantaResponse
{
    /** @var array headers returned as part of response */
    protected $headers;

    /**
     * Create a new instance of the response object with the specified
     * response headers.
     *
     * @param array $headers headers to include
     */
    public function __construct(array $headers)
    {
        $this->headers = $headers;
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
     * @param array $headers set the headers property to the specified value
     */
    public function setHeaders($headers)
    {
        $this->headers = $headers;
    }
}
