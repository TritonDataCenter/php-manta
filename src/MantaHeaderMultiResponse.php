<?php namespace Joyent\Manta;

/**
 * Response object that encapsulates the headers from multiple responses.
 * The last received header is typically returned with the ->getHeader() method.
 * @package Joyent\Manta
 */
class MantaHeaderMultiResponse extends MantaHeaderResponse
{
    /** @var array array of all header results from each response */
    protected $allHeaders;

    /**
     * MantaHeaderMultiResponse constructor.
     * @param array|null $headers headers for the last response
     * @param array $allHeaders all headers for all responses
     */
    public function __construct($headers = null, array $allHeaders = array())
    {
        if (!is_null($headers)) {
            parent::__construct($headers);
        }

        $this->allHeaders = $allHeaders;
    }

    /**
     * Get a list of all headers from all responses.
     *
     * @return array array of header responses
     */
    public function getAllHeaders()
    {
        return $this->allHeaders;
    }

    /**
     * Adds an additional header result to headers property.
     *
     * @param array $headers headers to add to allHeaders property
     */
    public function addHeaderToAllHeaders(array $headers)
    {
        $this->allHeaders[] = $headers;
    }

    /**
     * Merge array of headers into allHeaders
     * @param array $headers array of arrays of headers
     */
    public function mergeHeadersToAllHeaders(array $headers)
    {
        $this->allHeaders = array_merge($this->allHeaders, $headers);
    }
}
