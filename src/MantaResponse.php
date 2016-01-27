<?php namespace Joyent\Manta;

/**
 * Interface representing the base level of functionality to expose for
 * responses from the Manta API.
 *
 * @package Joyent\Manta
 */
interface MantaResponse
{
    /**
     * Returns the HTTP headers associated with the response.
     *
     * @return array HTTP headers
     */
    public function getHeaders();
}
