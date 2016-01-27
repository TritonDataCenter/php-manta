<?php namespace Joyent\Manta;

use Ramsey\Uuid\Uuid;

class MantaObjectResponseTest extends \PHPUnit_Framework_TestCase
{
    /** @test if we can access a response as an object */
    public function canAccessAsObject()
    {
        $object = new FooObject();
        $response = new \Joyent\Manta\MantaObjectResponse($object, self::headers());
        $this->assertEquals(
            $object->hello(),
            $response->hello(),
            'Could not access the same methods'
        );

        $this->assertEquals(
            $object->{'foo'},
            $response->{'foo'},
            'Could not access the same properties'
        );
    }

    /** @test if we can access the headers on a response */
    public function canAccessHeaders()
    {
        $object = new FooObject();
        $headers = self::headers();
        $response = new \Joyent\Manta\MantaObjectResponse($object, $headers);
        $this->assertEquals($headers, $response->getHeaders());
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

// @codingStandardsIgnoreStart
class FooObject extends \stdClass
{
    public function hello()
    {
        return "world";
    }

    public function __get($name)
    {
        return 'hello';
    }
}
// @codingStandardsIgnoreEnd
