<?php
class InstantiationTest extends PHPUnit_Framework_TestCase
{
    /** @test if a new instance can be created */
    public function canCreateNewInstanceFromEnvVars()
    {
        $instance = new \Joyent\Manta\MantaClient();
        $object = $instance->getObject('stegalink2.txt');
        print $object['data'];
    }
}
