<?php namespace Joyent\Manta;

use Ramsey\Uuid\Uuid;

class MantaStringResponseTest extends \PHPUnit_Framework_TestCase
{
    /** @test if we can access a response as a string */
    public function canAccessAsString()
    {
        $response = new \Joyent\Manta\MantaStringResponse("hello", self::headers());
        $this->assertEquals("hello", $response, 'Could not auto-convert to string');
    }

    /** @test if we can access the headers on a response */
    public function canAccessHeaders()
    {
        $headers = self::headers();
        $response = new \Joyent\Manta\MantaStringResponse("hello", $headers);
        $this->assertEquals($headers, $response->getHeaders());
    }

    /** @test if we can access the string value via the array interface */
    public function canAccessStringValueViaArrayInterface()
    {
        $response = new \Joyent\Manta\MantaStringResponse("hello", self::headers());
        $this->assertEquals("hello", $response['data']);
    }

    /** @test if we can access the string value via the array interface */
    public function canAccessHeadersValueViaArrayInterface()
    {
        $headers = self::headers();
        $response = new \Joyent\Manta\MantaStringResponse("hello", $headers);
        $this->assertEquals($headers, $response['headers']);
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
