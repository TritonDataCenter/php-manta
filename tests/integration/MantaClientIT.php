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

//    /** @test if we can add a single directory */
//    public function canCreateSingleDirectory()
//    {
//        $dirPath = sprintf('%s/%s', self::$testDir, uniqid());
//        self::$instance->putDirectory($dirPath);
//
//        $exists = self::$instance->exists($dirPath);
//
//        $dir = self::$instance->getObject($dirPath);
//    }

    /** @test if we can put an object and then get it */
    public function canPutAnObjectAndGetIt()
    {
        $data = "Plain-text test data";
        $objectPath = sprintf('%s/%s.txt', self::$testDir, uniqid());
        $wasAdded = self::$instance->putObject($data, $objectPath);
        $this->assertTrue($wasAdded, "Object not inserted: {$objectPath}");

        $objectResponse = self::$instance->getObject($objectPath);
        $this->assertArrayHasKey('data', $objectResponse);
        $objectContents = $objectResponse['data'];
        $this->assertEquals($data, $objectContents, "Remote object data is not equal to data stored");
    }

    /** @test if we can overwrite an existing object */
    public function canOverwriteAnObject()
    {
        $data = "Plain-text test data";
        $objectPath = sprintf("%s/%s.txt", self::$testDir, uniqid());
        $wasAdded = self::$instance->putObject($data, $objectPath);
        $this->assertTrue($wasAdded, "Object not inserted: {$objectPath}");

        $objectResponse = self::$instance->getObject($objectPath);
        $this->assertArrayHasKey('data', $objectResponse);
        $objectContents = $objectResponse['data'];
        $this->assertEquals($data, $objectContents, "Remote object data is not equal to data stored");

        $updatedData = "Plain-text test data - updated";
        $wasUpdated = self::$instance->putObject($updatedData, $objectPath);
        $this->assertTrue($wasUpdated, "Object not updated: {$objectPath}");
    }

    /** @test if we can properly write utf-8 data as read from a file into memory */
    public function canWriteUTF8FromFileInMemory()
    {
        $data = file_get_contents('../data/utf-8_file_contents.txt');
        $objectPath = sprintf("%s/%s.txt", self::$testDir, uniqid());
        $wasAdded = self::$instance->putObject($data, $objectPath);
        $this->assertTrue($wasAdded, "Object not inserted: {$objectPath}");

        $objectResponse = self::$instance->getObject($objectPath);
        $this->assertArrayHasKey('data', $objectResponse);
        $objectContents = $objectResponse['data'];
        $this->assertEquals($data, $objectContents, "Remote object data is not equal to data stored");
    }

    /** @test if we can properly write to a utf-8 filename */
    public function canWriteToUTF8Filename()
    {
        $data = "Plain-text test data";
        $filename = rtrim(file_get_contents('../data/utf-8_file_contents.txt'), "\n");
        $objectPath = sprintf("%s/%s.txt", self::$testDir, $filename);
        $wasAdded = self::$instance->putObject($data, $objectPath);
        $this->assertTrue($wasAdded, "Object not inserted: {$objectPath}");

        $objectResponse = self::$instance->getObject($objectPath);
        $this->assertArrayHasKey('data', $objectResponse);
        $objectContents = $objectResponse['data'];
        $this->assertEquals($data, $objectContents, "Remote object data is not equal to data stored");
    }

}
