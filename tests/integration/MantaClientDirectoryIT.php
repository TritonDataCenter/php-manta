<?php
class MantaClientDirectoryIT extends PHPUnit_Framework_TestCase
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
        self::$baseDir = "/{$account}/stor/php-test";
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

    /** @test if we can add a single directory */
    public function canCreateSingleDirectory()
    {
        $dirPath = sprintf('%s/%s', self::$testDir, uniqid());

        $this->assertFalse(
            self::$instance->exists($dirPath),
            "Directory path should not already exist: {$dirPath}"
        );

        self::$instance->putDirectory($dirPath);

        $this->assertTrue(
            self::$instance->exists($dirPath),
            "Directory path should exist: {$dirPath}"
        );

        $dir = self::$instance->getObjectAsStream($dirPath);
        $this->assertEquals(
            $dir['headers']['Content-Type'][0],
            'application/x-json-stream; type=directory',
            "Wrong content type for directory"
        );
    }

    /** @test if we can overwrite an existing directory */
    public function canOverwriteSingleDirectory()
    {
        $dirPath = sprintf('%s/%s', self::$testDir, uniqid());

        $this->assertFalse(
            self::$instance->exists($dirPath),
            "Directory path should not already exist: {$dirPath}"
        );

        self::$instance->putDirectory($dirPath);

        $this->assertTrue(
            self::$instance->exists($dirPath),
            "Directory path should exist: {$dirPath}"
        );

        $dir = self::$instance->getObjectAsStream($dirPath);
        $this->assertEquals(
            $dir['headers']['Content-Type'][0],
            'application/x-json-stream; type=directory',
            "Wrong content type for directory"
        );

        // This will error if there is a problem overwriting
        self::$instance->putDirectory($dirPath);
    }

    /** @test if we can overwrite an existing directory */
    public function canAddMultipleDirectories()
    {
        $dirPath = sprintf(
            '%s/%s/%s/%s',
            self::$testDir,
            uniqid(),
            uniqid(),
            uniqid()
        );

        $this->assertFalse(
            self::$instance->exists($dirPath),
            "Directory path should not already exist: {$dirPath}"
        );

        $response = self::$instance->putDirectory($dirPath, true);

        $this->assertTrue(
            self::$instance->exists($dirPath),
            "Directory path should exist: {$dirPath}"
        );

        $this->assertEquals(count($response['all_headers']), 6,
            "An unexpected number of header results were returned");

        $dir = self::$instance->getObjectAsStream($dirPath);
        $this->assertEquals(
            $dir['headers']['Content-Type'][0],
            'application/x-json-stream; type=directory',
            "Wrong content type for directory"
        );
    }

    /** @test if we can delete a single directory */
    public function canDeleteSingleDirectory()
    {
        $dirPath = sprintf('%s/%s', self::$testDir, uniqid());

        $this->assertFalse(
            self::$instance->exists($dirPath),
            "Directory path should not already exist: {$dirPath}"
        );

        self::$instance->putDirectory($dirPath);

        $this->assertTrue(
            self::$instance->exists($dirPath),
            "Directory path should exist: {$dirPath}"
        );

        $dir = self::$instance->getObjectAsStream($dirPath);
        $this->assertEquals(
            $dir['headers']['Content-Type'][0],
            'application/x-json-stream; type=directory',
            "Wrong content type for directory"
        );

        self::$instance->deleteDirectory($dirPath, false);

        $this->assertFalse(
            self::$instance->exists($dirPath),
            "Directory path shouldn't exist: {$dirPath}"
        );
    }

    /** @test if we can delete a multiple directories */
    public function canDeleteMultipleDirectories()
    {
        $basePath = sprintf(
            '%s/%s',
            self::$testDir,
            uniqid()
        );


        $dirPath = sprintf(
            '%s/%s/%s/%s',
            $basePath,
            uniqid(),
            uniqid(),
            uniqid()
        );

        $this->assertFalse(
            self::$instance->exists($dirPath),
            "Directory path should not already exist: {$dirPath}"
        );

        self::$instance->putDirectory($dirPath, true);

        $this->assertTrue(
            self::$instance->exists($dirPath),
            "Directory path should exist: {$dirPath}"
        );

        $response = self::$instance->deleteDirectory($basePath, true);

        $this->assertEquals(
            4,
            count($response['all_headers']),
            'The expected number of response headers were not found');

        $this->assertFalse(
            self::$instance->exists($basePath),
            "Directory path shouldn't exist: {$basePath}"
        );
    }
}
