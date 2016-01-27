<?php namespace Joyent\Manta;

use Ramsey\Uuid\Uuid;
use GuzzleHttp\Psr7;

class MantaClientObjectIT extends \PHPUnit_Framework_TestCase
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

    /** @test if we can put an object from a string and then get it */
    public function canPutObjectFromStringAnAndGetIt()
    {
        $data = "Plain-text test data";
        $objectPath = sprintf('%s/%s.txt', self::$testDir, (string)Uuid::uuid4());
        $putResponse = self::$instance->putObject($data, $objectPath);
        $this->assertNotNull($putResponse->getHeaders(), "Object not inserted: {$objectPath}");

        $objectContents = self::$instance->getObjectAsString($objectPath);
        $this->assertEquals($data, $objectContents, "Remote object data is not equal to data stored");
    }

    /** @test if we can put an object from a fopen resource and then get it */
    public function canPutObjectFromFOpenAnAndGetIt()
    {
        $filePath = realpath(dirname(__FILE__));
        $file = fopen("{$filePath}/../data/binary_file", 'r');

        try {
            $objectPath = sprintf('%s/%s.txt', self::$testDir, (string)Uuid::uuid4());
            $putResponse = self::$instance->putObject($file, $objectPath);
            $this->assertNotNull($putResponse->getHeaders(), "Object not inserted: {$objectPath}");

            $objectContents = self::$instance->getObjectAsString($objectPath);
            $actualContents = file_get_contents("{$filePath}/../data/binary_file", 'r');
            $this->assertEquals($actualContents, $objectContents, "Remote object data is not equal to data stored");
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
        }
    }

    /** @test if we can put an object from a stream and then get it */
    public function canPutObjectFromStreamAnAndGetIt()
    {
        $actualObject = "I'm a stream...";
        $stream = Psr7\stream_for($actualObject);
        $objectPath = sprintf('%s/%s.txt', self::$testDir, (string)Uuid::uuid4());
        $putResponse = self::$instance->putObject($stream, $objectPath);
        $this->assertNotNull($putResponse->getHeaders(), "Object not inserted: {$objectPath}");

        $objectContents = self::$instance->getObjectAsString($objectPath);
        $this->assertEquals($actualObject, $objectContents, "Remote object data is not equal to data stored");
    }

    /** @test if we can overwrite an existing object */
    public function canOverwriteAnObject()
    {
        $data = "Plain-text test data";
        $objectPath = sprintf("%s/%s.txt", self::$testDir, (string)Uuid::uuid4());
        $putResponse = self::$instance->putObject($data, $objectPath);
        $this->assertNotNull($putResponse->getHeaders(), "Object not inserted: {$objectPath}");

        $objectContents = self::$instance->getObjectAsString($objectPath);
        $this->assertEquals($data, $objectContents, "Remote object data is not equal to data stored");

        $updatedData = "Plain-text test data - updated";
        $wasUpdated = self::$instance->putObject($updatedData, $objectPath);
        $this->assertNotNull($wasUpdated->getHeaders(), "Object not updated: {$objectPath}");
    }

    /** @test if we can properly write utf-8 data as read from a file into memory */
    public function canWriteUTF8FromFileInMemory()
    {
        $filePath = realpath(dirname(__FILE__));
        $data = file_get_contents("{$filePath}/../data/utf-8_file_contents.txt");
        $objectPath = sprintf("%s/%s.txt", self::$testDir, (string)Uuid::uuid4());
        $putResponse = self::$instance->putObject($data, $objectPath);
        $this->assertNotNull($putResponse->getHeaders(), "Object not inserted: {$objectPath}");

        $objectContents = self::$instance->getObjectAsString($objectPath);
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
        $this->assertNotNull($putResponse->getHeaders(), "Object not inserted: {$objectPath}");

        $objectContents = self::$instance->getObjectAsString($objectPath);
        $this->assertEquals($data, $objectContents, "Remote object data is not equal to data stored");
    }

    /** @test if we can get an object as a file */
    public function canPutObjectAndGetAsFile()
    {
        $data = "Plain-text test data";
        $objectPath = sprintf('%s/%s.txt', self::$testDir, (string)Uuid::uuid4());
        $putResponse = self::$instance->putObject($data, $objectPath);
        $this->assertNotNull($putResponse->getHeaders(), "Object not inserted: {$objectPath}");

        $file = self::$instance->getObjectAsFile($objectPath);

        try {
            $objectContents = file_get_contents($file);
            $this->assertEquals($data, $objectContents, "Remote object data is not equal to data stored");
        } finally {
            unlink($file);
        }
    }

    /** @test if we can delete an object */
    public function canPutAndDeleteObject()
    {
        $data = "Plain-text test data";
        $objectPath = sprintf('%s/%s.txt', self::$testDir, (string)Uuid::uuid4());
        self::$instance->putObject($data, $objectPath);
        $this->assertTrue(self::$instance->exists($objectPath));

        self::$instance->deleteObject($objectPath);
        $this->assertFalse(
            self::$instance->exists($objectPath),
            "Object wasn't deleted: {$objectPath}"
        );
    }



    /** @test if we can delete an object */
    public function canPutAsyncAndDeleteObject()
    {
        $data = "Plain-text test data [async]";
        $objectPath = sprintf('%s/%s.txt', self::$testDir, (string)Uuid::uuid4());
        self::$instance->putObjectAsync($data, $objectPath);

        $tries = 20;

        for ($try = 0; $try < $tries && !self::$instance->exists($objectPath); $try++) {
            sleep(2);
        }

        $this->assertTrue(self::$instance->exists($objectPath));

        self::$instance->deleteObject($objectPath);
        $this->assertFalse(
            self::$instance->exists($objectPath),
            "Object wasn't deleted: {$objectPath}"
        );
    }

    /** @test if we can create a snaplink */
    public function canCreateSnapLink()
    {
        $data = "Plain-text test data";
        $objectPath = sprintf('%s/%s.txt', self::$testDir, (string)Uuid::uuid4());
        self::$instance->putObject($data, $objectPath);
        $this->assertTrue(self::$instance->exists($objectPath));

        $linkPath = sprintf('%s/%s.txt', self::$testDir, (string)Uuid::uuid4());

        $response = self::$instance->putSnapLink($objectPath, $linkPath);
        $this->assertNotNull($response->getHeaders(), "Object not inserted: {$objectPath}");

        $this->assertTrue(
            self::$instance->exists($linkPath),
            "Snaplink wasn't created: {$linkPath}"
        );

        $this->assertEquals(
            $data,
            self::$instance->getObjectAsString($objectPath),
            "Snaplink data isn't identical"
        );
    }

    /** @test if we can get an objects's metadata */
    public function canGetObjectMetadata()
    {
        $data = "Plain-text test data";
        $objectPath = sprintf('%s/%s.txt', self::$testDir, (string)Uuid::uuid4());
        self::$instance->putObject($data, $objectPath);

        $metadata = self::$instance->getObjectMetadata($objectPath);
        $this->assertNotNull($metadata->getHeaders());
    }
}
