<?php namespace Joyent\Manta\Example;

/**
 * This example illustrates how you would put a file using php-manta.
 */

// This is needed because we are executing these examples from the CLI
$curdir = realpath(dirname(__FILE__));
require_once "{$curdir}/../vendor/autoload.php";

use GuzzleHttp\Psr7;
use Joyent\Manta\MantaClient;
use Joyent\Manta\MantaException;

function usage() {
    print <<<EOD
This is an example allows you to put the contents of STDIN into a remote object
on Manta. It relies on your MANTA environment variables being set up correctly.

Usage:
 STDIN | mput.php <remote object path>

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

// Test if object is a directory
if ($client->isDirectory($path)) {
    fwrite(STDERR, "mput: cannot write object to directory path\n");
    exit(1);
}

// If you are doing this for real, use streams
$contents = file_get_contents('php://stdin', 'r');

$client->putObject($contents, $path);
