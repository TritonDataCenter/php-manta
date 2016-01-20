<?php
class MantaClientIT extends PHPUnit_Framework_TestCase
{
    /** @var string path to test data directory in Manta store */
    private $testDir;

    /** @var \Joyent\Manta\MantaClient test instance of Manta client */
    private $instance;

    /** @beforeClass setup client instance and create test directory */
    public function setup() {
        // Instantiate using environment variables
        $this->instance = new \Joyent\Manta\MantaClient();
        $account = getenv(\Joyent\Manta\MantaClient::MANTA_USER_ENV_KEY);
        $prefix = uniqid();
        $this->testDir = "/{$account}/stor/{$prefix}";
        $this->instance->putDirectory($this->testDir);
    }

    /** @afterClass clean up any test files */
    public function cleanUp() {
        if ($this->instance) {
            $this->instance->deleteDirectory($this->testDir);
        }
    }

    /** @test if we can put an object and then get it */
    public function canPutAnObjectAndGetIt()
    {
        $data = "Plain-text test data";
        $objectPath = sprintf("%s/%s.txt", $this->testDir, uniqid());
        $wasAdded = $this->instance->putObject($data, $objectPath);
        $this->assertTrue($wasAdded, "Object not inserted: {$objectPath}");

        $objectResponse = $this->instance->getObject($objectPath);
        $this->assertArrayHasKey('data', $objectResponse);
        $objectContents = $objectResponse['data'];
        $this->assertEquals($data, $objectContents, "Remote object data is not equal to data stored");
    }
}