<?php namespace Joyent\Manta\Example;

/**
 * This example illustrates how you would list directories using php-manta.
 */

// This is needed because we are executing these examples from the CLI
$curdir = realpath(dirname(__FILE__));
require_once "{$curdir}/../vendor/autoload.php";

use DateTime;
use DateTimeZone;
use Joyent\Manta\MantaClient;
use Joyent\Manta\MantaException;

function usage() {
    print <<<EOD
This is an example allows you to list directories. It relies on your MANTA
environment variables being set up correctly.

Usage:
 mls.php <remote path>

EOD;
}

if (count($argv) <= 1 || $argv[1] == 'help' || $argv[1] == '--help') {
    usage();
    exit(0);
}

/* Since we didn't specify any parameters to the constructor, we are pulling
 * all of our configuration from environment variables. */
$client = new MantaClient();

// Path to object or directory on Manta
$path = $argv[1];

if (substr($path, 0, 2) == '~~') {
    $path = $client->getHomeDirectory() . substr($path, 2);
}

try {
    // This lists a directory on Manta
    $contents = $client->listDirectory($path);
} catch (MantaException $e) {
    // This illustrates how you can trap on the HTTP error code.
    if ($e->getCode() == 404) {
        fwrite(STDERR, "mls: cannot access $path: No such file or directory\n");
        exit(1);
    }

    throw $e;
}

// This detects if the result returned is a directory
if ($contents->getHeaders()['Content-Type'][0] == MantaClient::DIR_CONTENT_TYPE) {
    foreach ($contents as $item) {
        $dirMarker = $item['type'] == 'directory' ? '/' : '';
        printf("%s %s%s\n", $item['mtime'], $item['name'], $dirMarker);
    }
// Otherwise, it is an object and we have to handle it differently
} else {
    /* We can get the mtime for the object if we do an additional call,
     * but for efficiency we don't. */
    $lastModified = $contents->getHeaders()['Last-Modified'][0];
    $mtime = DateTime::createFromFormat(
        'D, d M Y H:i:s \G\M\T',
        $lastModified,
        new DateTimeZone('UTC')
    );
    $name = $path;
    printf("%s %s\n", $mtime->format('Y-m-d\TH:i:s.000\Z'), $name);
}

