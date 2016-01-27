<?php namespace Joyent\Manta;

/**
 * Manta job phase convenience class for constructing a valid job phase object
 * http://apidocs.joyent.com/manta/api.html#CreateJob
 */
class MantaJobPhase
{
    /**
     * Construction
     *
     * @param phase   Array of Manta job phase options
     */
    public function __construct($phase)
    {
        $props = array(
            'type',
            'assets',
            'exec',
            'init',
            'count',
            'memory',
            'disk',
        );
        foreach ($props as $prop) {
            if (isset($phase[$prop])) {
                $this->{$prop} = $phase[$prop];
            }
        }
    }
}
