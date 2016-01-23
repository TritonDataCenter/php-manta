<?php
class MantaClientIT extends PHPUnit_Framework_TestCase
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

    /** @test if we can add a single directory */
    public function canCreateSingleDirectory()
    {
        $dirPath = sprintf('%s/%s', self::$testDir, uniqid());

        $this->assertFalse(
            self::$instance->exists($dirPath),
            "Directory path should not already exist: {$dirPath}");

        self::$instance->putDirectory($dirPath);

        $this->assertTrue(
            self::$instance->exists($dirPath),
            "Directory path should exist: {$dirPath}");

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
            "Directory path should not already exist: {$dirPath}");

        self::$instance->putDirectory($dirPath);

        $this->assertTrue(
            self::$instance->exists($dirPath),
            "Directory path should exist: {$dirPath}");

        $dir = self::$instance->getObjectAsStream($dirPath);
        $this->assertEquals(
            $dir['headers']['Content-Type'][0],
            'application/x-json-stream; type=directory',
            "Wrong content type for directory"
        );

        // This will error if there is a problem overwriting
        self::$instance->putDirectory($dirPath);
    }

    /** @test if we can put an object from a string and then get it */
    public function canPutObjectFromStringAnAndGetIt()
    {
        $data = "Plain-text test data";
        $objectPath = sprintf('%s/%s.txt', self::$testDir, uniqid());
        $putResponse = self::$instance->putObject($data, $objectPath);
        $this->assertArrayHasKey('headers', $putResponse, "Object not inserted: {$objectPath}");

        $objectResponse = self::$instance->getObjectAsString($objectPath);
        $this->assertArrayHasKey('data', $objectResponse);
        $objectContents = $objectResponse['data'];
        $this->assertEquals($data, $objectContents, "Remote object data is not equal to data stored");
    }

    /** @test if we can put an object from a fopen resource and then get it */
    public function canPutObjectFromFOpenAnAndGetIt()
    {
        $filePath = realpath(dirname(__FILE__));
        $file = fopen("{$filePath}/../data/binary_file", 'r');

        try
        {
            $objectPath = sprintf('%s/%s.txt', self::$testDir, uniqid());
            $putResponse = self::$instance->putObject($file, $objectPath);
            $this->assertArrayHasKey('headers', $putResponse, "Object not inserted: {$objectPath}");

            $objectResponse = self::$instance->getObjectAsString($objectPath);
            $this->assertArrayHasKey('data', $objectResponse);
            $objectContents = $objectResponse['data'];
            $actualContents = file_get_contents("{$filePath}/../data/binary_file", 'r');
            $this->assertEquals($actualContents, $objectContents, "Remote object data is not equal to data stored");
        } finally
        {
            if (is_resource($file))
            {
                fclose($file);
            }
        }
    }

    /** @test if we can put an object from a stream and then get it */
    public function canPutObjectFromStreamAnAndGetIt()
    {
        $actualObject = "I'm a stream...";
        $stream = GuzzleHttp\Psr7\stream_for($actualObject);
        $objectPath = sprintf('%s/%s.txt', self::$testDir, uniqid());
        $putResponse = self::$instance->putObject($stream, $objectPath);
        $this->assertArrayHasKey('headers', $putResponse, "Object not inserted: {$objectPath}");

        $objectResponse = self::$instance->getObjectAsString($objectPath);
        $this->assertArrayHasKey('data', $objectResponse);
        $objectContents = $objectResponse['data'];
        $this->assertEquals($actualObject, $objectContents, "Remote object data is not equal to data stored");
    }

    /** @test if we can overwrite an existing object */
    public function canOverwriteAnObject()
    {
        $data = "Plain-text test data";
        $objectPath = sprintf("%s/%s.txt", self::$testDir, uniqid());
        $putResponse = self::$instance->putObject($data, $objectPath);
        $this->assertArrayHasKey('headers', $putResponse, "Object not inserted: {$objectPath}");

        $objectResponse = self::$instance->getObjectAsString($objectPath);
        $this->assertArrayHasKey('data', $objectResponse);
        $objectContents = $objectResponse['data'];
        $this->assertEquals($data, $objectContents, "Remote object data is not equal to data stored");

        $updatedData = "Plain-text test data - updated";
        $wasUpdated = self::$instance->putObject($updatedData, $objectPath);
        $this->assertArrayHasKey('headers', $wasUpdated, "Object not updated: {$objectPath}");
    }

    /** @test if we can properly write utf-8 data as read from a file into memory */
    public function canWriteUTF8FromFileInMemory()
    {
        $filePath = realpath(dirname(__FILE__));
        $data = file_get_contents("{$filePath}/../data/utf-8_file_contents.txt");
        $objectPath = sprintf("%s/%s.txt", self::$testDir, uniqid());
        $putResponse = self::$instance->putObject($data, $objectPath);
        $this->assertArrayHasKey('headers', $putResponse, "Object not inserted: {$objectPath}");

        $objectResponse = self::$instance->getObjectAsString($objectPath);
        $this->assertArrayHasKey('data', $objectResponse);
        $objectContents = $objectResponse['data'];
        $this->assertEquals($data, $objectContents, "Remote object data is not equal to data stored");
    }

    /** @test if we can properly write to a utf-8 filename */
    public function canWriteToUTF8Filename()
    {
        $data = "Plain-text test data";
        $filePath = realpath(dirname(__FILE__));
        $filename = rtrim(file_get_contents("{$filePath}/../data/utf-8_file_contents.txt"), "\n");
        $objectPath = sprintf("%s/%s.txt", self::$testDir, $filename);
        $putResponse = self::$instance->putObject($data, $objectPath);
        $this->assertArrayHasKey('headers', $putResponse, "Object not inserted: {$objectPath}");

        $objectResponse = self::$instance->getObjectAsString($objectPath);
        $this->assertArrayHasKey('data', $objectResponse);
        $objectContents = $objectResponse['data'];
        $this->assertEquals($data, $objectContents, "Remote object data is not equal to data stored");
    }

}
