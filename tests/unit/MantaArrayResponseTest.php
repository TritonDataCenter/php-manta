<?php namespace Joyent\Manta;

use Ramsey\Uuid\Uuid;

class MantaArrayResponseTest extends \PHPUnit_Framework_TestCase
{
    /** @test if we can access a response as a array */
    public function canAccessAsArray()
    {
        $array = self::sampleArray();
        $response = new \Joyent\Manta\MantaArrayResponse($array, self::headers());
        $this->assertEquals($array, (array)$response, 'Could not auto-convert to an array');
    }

    /** @test if we can access the headers on a response */
    public function canAccessHeaders()
    {
        $headers = self::headers();
        $array = self::sampleArray();
        $response = new \Joyent\Manta\MantaArrayResponse($array, $headers);
        $this->assertEquals($headers, $response->getHeaders());
    }

    /** @test if we can access the array via the array interface */
    public function canTestArrayValueViaArrayInterface()
    {
        $array = self::sampleArray();
        $response = new \Joyent\Manta\MantaArrayResponse($array, self::headers());
        $this->assertArrayHasKey('test', $response);
    }

    /** @test if we can access the array via the array interface */
    public function canAccessArrayValueViaArrayInterface()
    {
        $array = self::sampleArray();
        $response = new \Joyent\Manta\MantaArrayResponse($array, self::headers());
        $this->assertEquals("hello", $response['test']);
    }

    /** @test if we can unset an the array value via the array interface */
    public function canUnsetArrayValueViaArrayInterface()
    {
        $array = self::sampleArray();
        $response = new \Joyent\Manta\MantaArrayResponse($array, self::headers());
        unset($response['test']);
        $this->assertArrayNotHasKey('test', $response);
    }

    protected static function sampleArray()
    {
        return array(
            'test' => 'hello'
        );
    }

    protected static function headers()
    {
        return array(
            'Content-Type' => 'text/plain',
            'Content-Length' => 5,
            'x-request-id' => (string)Uuid::uuid4()
        );
    }
}
