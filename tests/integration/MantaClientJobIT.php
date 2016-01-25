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

    /** @test if we can create a job */
    public function canCreateAndCancelJob() {
        $testId = uniqid();

        $phases = array(
            array(
                'type' => 'map',
                'exec' => "cat"
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

    /** @test if we can attach inputs to a job */
    public function canAttachInputsToJobAndRunJob()
    {
        $testId = uniqid();

        $objectPath = sprintf('%s/%s', self::$testDir, $testId);
        $data = <<<EOD
exclude me
bb 1
another ignored line
bb 2
also ignore this
bb 3
bb 3
EOD;

        self::$instance->putObject($data, $objectPath);

        $this->assertTrue(self::$instance->exists($objectPath));

        $phases = array(
            array(
                'type' => 'map',
                'exec' => "grep bb"
            ),
            array(
                'type' => 'reduce',
                'exec' => 'sort | uniq'
            )
        );

        $name = "php-manta-test-{$testId}";

        $job = self::$instance->createJob($phases, $name);

        $inputs = array($objectPath);
        $added = self::$instance->addJobInputs($job['jobId'], $inputs);
        $this->assertArrayHasKey('headers', $added, 'Inputs not attached');

        $ended = self::$instance->endJobInput($job['jobId']);
        $this->assertArrayHasKey('headers', $ended, 'Job not ended');

        $tries = 20;
        $state = 'running';

        for ($try = 0; $try < $tries && $state != 'done'; $try++) {
            sleep(2);
            $state = self::$instance->getJobState($job['jobId']);
        }

        $this->assertEquals('done', $state, 'Job state should be done');

        $expected = <<<EOD
bb 1
bb 2
bb 3

EOD;

        // This uses the live endpoint
        $liveOutputPath = self::$instance->getJobLiveOutputs($job['jobId'])['data'][0];
        $actualLiveOutput = self::$instance->getObjectAsString($liveOutputPath)['data'];

        $this->assertEquals(
            $expected,
            $actualLiveOutput,
            'Job output did not match expectation'
        );

        // This uses the endpoint that returns data for archived jobs
        $outputPath = self::$instance->getJobOutputs($job['jobId'])['data'][0];
        $actualOutput = self::$instance->getObjectAsString($outputPath)['data'];

        $this->assertEquals(
            $expected,
            $actualOutput,
            'Job output did not match expectation'
        );
    }
}
