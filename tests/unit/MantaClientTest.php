<?php namespace Joyent\Manta;

class MantaClientTest extends \PHPUnit_Framework_TestCase
{
    /** @test if a new instance can be created */
    public function canCreateNewInstanceFromEnvVars()
    {
        /* This test along with all other tests assume you are using
         * environment variables to configure the Manta client. */
        new MantaClient();
    }

    /** @test if home directory works */
    public function canGetHomeDirectoryForUser()
    {
        $user = 'fake.user';
        $client = new MantaClient(null, $user);

        $this->assertEquals(
            "/{$user}",
            $client->getHomeDirectory(),
            "Incorrect home directory returned for user"
        );
    }
}
