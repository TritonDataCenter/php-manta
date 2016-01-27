<?php namespace Joyent\Manta\Example;

/**
 * This example illustrates how you would get a file using php-manta.
 */

// This is needed because we are executing these examples from the CLI
$curdir = realpath(dirname(__FILE__));
require_once "{$curdir}/../vendor/autoload.php";

use Joyent\Manta\MantaClient;
use Joyent\Manta\MantaException;

function usage() {
    print <<<EOD
This is an example allows you to get an object to STDOUT. It relies on your MANTA
environment variables being set up correctly.

Usage:
 mget.php <remote object path>

EOD;
}

if (count($argv) <= 1 || $argv[1] == 'help' || $argv[1] == '--help') {
    usage();
    exit(0);
}

/* Since we didn't specify any parameters to the constructor, we are pulling
 * all of our configuration from environment variables. */
$client = new MantaClient();

// Path to object
$path = $argv[1];

if (substr($path, 0, 2) == '~~') {
    $path = $client->getHomeDirectory() . substr($path, 2);
}

$stream = null;
$bufferSize = 1024;

try {
    $stream = $client->getObjectAsStream($path);

    // This is how we detect that a stream is referring to a directory
    if ($stream->getHeaders()['Content-Type'][0] == MantaClient::DIR_CONTENT_TYPE) {
        fwrite(STDERR, "mls: cannot get $path: This is a directory\n");
        exit(1);
    }

    // This is how you would read a stream
    while( ($buffer = $stream->read($bufferSize)) != null) {
        fwrite(STDOUT, $buffer);
    }
} catch (MantaException $e) {
    // This illustrates how you can trap on the HTTP error code.
    if ($e->getCode() == 404) {
        fwrite(STDERR, "mls: cannot access $path: No such object\n");
        exit(1);
    }

    throw $e;
} finally {
    // Always close your streams
    if (!is_null($stream)) {
        $stream->close();
    }
}
