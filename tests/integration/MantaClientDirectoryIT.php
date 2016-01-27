<?php namespace Joyent\Manta;

use Ramsey\Uuid\Uuid;

class MantaClientDirectoryIT extends \PHPUnit_Framework_TestCase
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
        self::$baseDir = "/{$account}/stor/php-test";
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

    /** @test if we can add a single directory */
    public function canCreateSingleDirectory()
    {
        $dirPath = sprintf('%s/%s', self::$testDir, (string)Uuid::uuid4());

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
            $dir->getHeaders()['Content-Type'][0],
            'application/x-json-stream; type=directory',
            "Wrong content type for directory"
        );
    }

    /** @test if we can overwrite an existing directory */
    public function canOverwriteSingleDirectory()
    {
        $dirPath = sprintf('%s/%s', self::$testDir, (string)Uuid::uuid4());

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
            $dir->getHeaders()['Content-Type'][0],
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
            (string)Uuid::uuid4(),
            (string)Uuid::uuid4(),
            (string)Uuid::uuid4()
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

        // We skip running an operation of we are a subuser
        $expectedNoOfHeaders = empty(self::$instance->getSubuser()) ? 6 : 5;

        $this->assertEquals(
            count($response->getAllHeaders()),
            $expectedNoOfHeaders,
            "An unexpected number of header results were returned"
        );

        $dir = self::$instance->getObjectAsStream($dirPath);
        $this->assertEquals(
            $dir->getHeaders()['Content-Type'][0],
            'application/x-json-stream; type=directory',
            "Wrong content type for directory"
        );
    }

    /** @test if we can delete a single directory */
    public function canDeleteSingleDirectory()
    {
        $dirPath = sprintf('%s/%s', self::$testDir, (string)Uuid::uuid4());

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
            $dir->getHeaders()['Content-Type'][0],
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
            (string)Uuid::uuid4()
        );


        $dirPath = sprintf(
            '%s/%s/%s/%s',
            $basePath,
            (string)Uuid::uuid4(),
            (string)Uuid::uuid4(),
            (string)Uuid::uuid4()
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
            count($response->getAllHeaders()),
            'The expected number of response headers were not found'
        );

        $this->assertFalse(
            self::$instance->exists($basePath),
            "Directory path shouldn't exist: {$basePath}"
        );
    }

    /** @test if we can list directory contents */
    public function canListDirectoryContents()
    {
        $dirPath = sprintf('%s/%s', self::$testDir, (string)Uuid::uuid4());

        self::$instance->putDirectory($dirPath);

        $object1Name = (string)Uuid::uuid4();
        $object2Name = (string)Uuid::uuid4();
        $object3Name = (string)Uuid::uuid4();

        $object1Path = sprintf('%s/%s', $dirPath, $object1Name);
        $object2Path = sprintf('%s/%s', $dirPath, $object2Name);
        $object3Path = sprintf('%s/%s', $dirPath, $object3Name);

        $data = "sample content";
        self::$instance->putObject($data, $object1Path);
        self::$instance->putObject($data, $object2Path);
        self::$instance->putObject($data, $object3Path);

        $contents = (array)self::$instance->listDirectory($dirPath);

        $nameMatcher = function ($name) {
            return function ($item) use ($name) {
                return $item['name'] == $name;
            };
        };

        $foundObject1 = array_filter($contents, $nameMatcher($object1Name));
        $this->assertEquals(1, count($foundObject1));

        $foundObject2 = array_filter($contents, $nameMatcher($object3Name));
        $this->assertEquals(1, count($foundObject2));

        $foundObject3 = array_filter($contents, $nameMatcher($object3Name));
        $this->assertEquals(1, count($foundObject3));
    }

    /** @test if we can detect a directory */
    public function canDetectDirectory()
    {
        $dirPath = sprintf('%s/%s', self::$testDir, (string)Uuid::uuid4());
        self::$instance->putDirectory($dirPath);

        $this->assertTrue(
            self::$instance->isDirectory($dirPath),
            'Could not detect the presence of a directory'
        );
    }

    /** @test if we can detect a directory */
    public function canDetectNotDirectory()
    {
        $dirPath = sprintf('%s/%s', self::$testDir, (string)Uuid::uuid4());
        self::$instance->putDirectory($dirPath);

        $data = 'This is sample data';
        $objectPath = sprintf("%s/%s", $dirPath, (string)Uuid::uuid4());
        self::$instance->putObject($data, $objectPath);

        $this->assertFalse(
            self::$instance->isDirectory($objectPath),
            'Could not detect the lack of a directory'
        );
    }

    /** @test if we can get a directory's metadata */
    public function canGetDirectoryMetadata()
    {
        $dirPath = sprintf('%s/%s', self::$testDir, (string)Uuid::uuid4());
        self::$instance->putDirectory($dirPath);

        $metadata = self::$instance->getObjectMetadata($dirPath);

        $this->assertNotNull($metadata->getHeaders());
    }
}
