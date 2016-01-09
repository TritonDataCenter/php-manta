<?php
class InstantiationTest extends PHPUnit_Framework_TestCase
{
    private function instance()
    {
        return new \Joyent\Manta\MantaClient(
            $_ENV['MANTA_URL'],
            $_ENV['MANTA_USER'],
            $_ENV['MANTA_KEY_ID'],
            $_ENV['MANTA_KEY_PATH']
        );
    }

    /** @test if a new instance can be created */
    public function canCreateNewInstance()
    {
        $this->instance();
    }
}
