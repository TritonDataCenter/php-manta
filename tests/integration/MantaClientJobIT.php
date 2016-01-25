<?php

class MantaClientJobIT extends PHPUnit_Framework_TestCase
{
    /** @var string path to directory containing the test directory */
    private static $baseDir;

    /** @var string path to test data directory in Manta store */
    private static $testDir;

    /** @var \Joyent\Manta\MantaClient test instance of Manta client */
    private static $instance;

    /** @beforeClass setup client instance and create test directory */
    public static function setUpInstance() {
        // Instantiate using environment variables
        self::$instance = new \Joyent\Manta\MantaClient();
        $account = getenv(\Joyent\Manta\MantaClient::MANTA_USER_ENV_KEY);
        $prefix = uniqid();
        self::$baseDir = "/{$account}/stor/php-test/";
        self::$testDir = sprintf('%s/%s', self::$baseDir, $prefix);

        self::$instance->putDirectory(self::$baseDir);
        self::$instance->putDirectory(self::$testDir);
    }

    /** @afterClass clean up any test files */
    public static function cleanUp() {
        if (self::$instance) {
            self::$instance->deleteDirectory(self::$testDir, true);
        }
    }

    /** @test if we can create a Manta job */
    public function canCreateJob() {
        $testId = uniqid();

        $objectPath = sprintf('%s/%s', self::$testDir, $testId);
        $data = 'Job test object';
        self::$instance->putObject($data, $objectPath);

        $this->assertTrue(self::$instance->exists($objectPath));

        $phases = array(
            array(
                'type' => 'map',
                'exec' => "cat {$objectPath}"
            )
        );

        $name = "php-manta-test-{$testId}";

        $job = self::$instance->createJob($phases, $name);

        try {
            $this->assertNotEmpty(
                $job['jobId'],
                'We should get an id after creating a new job'
            );
            $this->assertNotEmpty($job['location']);
            $this->assertNotEmpty($job['headers']);
        } finally {
            self::$instance->cancelJob($job['jobId']);
        }
    }
}
