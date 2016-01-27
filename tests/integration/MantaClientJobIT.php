<?php namespace Joyent\Manta;

use Ramsey\Uuid\Uuid;

class MantaClientJobIT extends \PHPUnit_Framework_TestCase
{
    /** @var string path to directory containing the test directory */
    private static $baseDir;

    /** @var string path to test data directory in Manta store */
    private static $testDir;

    /** @var \Joyent\Manta\MantaClient test instance of Manta client */
    private static $instance;

    /** @beforeClass setup client instance and create test directory */
    public static function setUpInstance()
    {
        // Instantiate using environment variables
        self::$instance = new MantaClient();
        $account = getenv(MantaClient::MANTA_USER_ENV_KEY);
        $prefix = (string)Uuid::uuid4();
        self::$baseDir = "/{$account}/stor/php-test/";
        self::$testDir = sprintf('%s/%s', self::$baseDir, $prefix);

        self::$instance->putDirectory(self::$baseDir);
        self::$instance->putDirectory(self::$testDir);
    }

    /** @afterClass clean up any test files */
    public static function cleanUp()
    {
        if (self::$instance) {
            self::$instance->deleteDirectory(self::$testDir, true);
        }
    }

    /** @test if we can create a job */
    public function canCreateAndCancelJob()
    {
        $testId = (string)Uuid::uuid4();

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
            $this->assertNotEmpty($job->getHeaders());
        } finally {
            self::$instance->cancelJob($job['jobId']);
        }
    }

    /** @test if we can attach inputs to a job */
    public function canAttachInputsToJobAndRunJob()
    {
        if (!empty(self::$instance->getSubuser())) {
            $this->markTestSkipped('Skipping. This operation isn\'t supported by subusers');
        }

        $testId = (string)Uuid::uuid4();

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
        $this->assertNotNull($added->getHeaders(), 'Inputs not attached');

        $ended = self::$instance->endJobInput($job['jobId']);
        $this->assertNotNull($ended->getHeaders(), 'Job not ended');

        $tries = 20;
        $state = 'running';

        for ($try = 0; $try < $tries && $state == 'running'; $try++) {
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
        $liveOutputPath = self::$instance->getJobLiveOutputs($job['jobId'])[0];
        $actualLiveOutput = self::$instance->getObjectAsString($liveOutputPath);

        $this->assertEquals(
            $expected,
            $actualLiveOutput,
            'Job output did not match expectation'
        );

        // Sleep because there may be a delay moving jobs into the archived state
        sleep(2);

        // This uses the endpoint that returns data for archived jobs
        $outputPath = self::$instance->getJobOutputs($job['jobId'])[0];
        $actualOutput = self::$instance->getObjectAsString($outputPath);

        $this->assertEquals(
            $expected,
            $actualOutput,
            'Job output did not match expectation'
        );

        // Verify that the job input was recorded successfully
        $input = self::$instance->getJobInput($job['jobId']);
        $inputPath = $input[0];
        $actualInput = self::$instance->getObjectAsString($inputPath);

        $this->assertEquals(
            $data,
            $actualInput,
            'Input data stored on Manta did not match data sent to Manta'
        );
    }

    /** @test if we can list jobs */
    public function canListJobs()
    {
        $response = self::$instance->listJobs();
        $this->assertNotNull($response->getHeaders());
        $this->assertFalse(empty($response), 'There should be at least job listed');
    }

    /** @test if we can list failed jobs */
    public function canGetFailedJobs()
    {
        if (!empty(self::$instance->getSubuser())) {
            $this->markTestSkipped('Skipping. This operation isn\'t supported by subusers');
        }

        $testId = (string)Uuid::uuid4();

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
                'exec' => "grep foo"
            ),
            array(
                'type' => 'reduce',
                'exec' => 'sort | uniq'
            )
        );

        $name = "php-manta-test-failed-job-{$testId}";

        $job = self::$instance->createJob($phases, $name);

        $inputs = array($objectPath);
        $added = self::$instance->addJobInputs($job['jobId'], $inputs);
        $this->assertNotNull($added->getHeaders(), 'Inputs not attached');

        $ended = self::$instance->endJobInput($job['jobId']);
        $this->assertNotNull($ended->getHeaders(), 'Job not ended');

        $tries = 20;
        $state = 'running';

        for ($try = 0; $try < $tries && $state == 'running'; $try++) {
            sleep(2);
            $state = self::$instance->getJobState($job['jobId']);
        }

        $this->assertEquals('done', $state, 'Job state should be done');

        // Test live failures

        $liveFailures = self::$instance->getLiveJobFailures($job['jobId']);
        $actualFailureInput = self::$instance->getObjectAsString($liveFailures[0]);

        $this->assertEquals(
            $data,
            $actualFailureInput,
            "Live failing input stored on Manta should equal input sent to Manta"
        );

        // Sleep because there may be a delay moving jobs into the archived state
        sleep(2);

        // Test archived failures

        $failures = self::$instance->getJobFailures($job['jobId']);
        $actualFailureInput = self::$instance->getObjectAsString($failures[0]);

        $this->assertEquals(
            $data,
            $actualFailureInput,
            "Failing input stored on Manta should equal input sent to Manta"
        );

        // Test live error retrieval

        $liveErrors = self::$instance->getLiveJobErrors($job['jobId']);

        $this->assertEquals(
            'UserTaskError',
            $liveErrors[0]['code'],
            'Error code did not match expectation'
        );

        // Test archived error retrieval

        $errors = self::$instance->getJobErrors($job['jobId']);

        $this->assertEquals(
            'UserTaskError',
            $errors[0]['code'],
            'Error code did not match expectation'
        );
    }
}
