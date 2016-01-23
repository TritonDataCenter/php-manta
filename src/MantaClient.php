<?php namespace Joyent\Manta;
/**
 * This is a library for accessing the Joyent Manta Services via
 * the public or the private cloud.
 */

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Middleware;

/**
 * Manta client class for interacting with the Manta REST API service endpoint.
 *
 * @see https://www.joyent.com/object-storage More information about Manta
 * @copyright 2016 Joyent Inc.
 * @license https://www.mozilla.org/en-US/MPL/2.0/
 * @author Robert Bates
 * @author Elijah Zupancic <elijah@zupancic.name>
 */
class MantaClient
{
    /** Environment variable for Manta REST endpoint. */
    const MANTA_URL_ENV_KEY = 'MANTA_URL';
    /** Environment variable for Manta account name. */
    const MANTA_USER_ENV_KEY = 'MANTA_USER';
    /** Environment variable for private encryption key fingerprint. */
    const MANTA_KEY_ID_ENV_KEY = "MANTA_KEY_ID";
    /** Environment variable indicating the path to the private key. */
    const MANTA_KEY_PATH_ENV_KEY = 'MANTA_KEY_PATH';
    /** Default value for Manta REST endpoint. */
    const DEFAULT_MANTA_URL = 'https://us-east.manta.joyent.com:443';
    /** Default path suffix for private encryption key. */
    const DEFAULT_MANTA_KEY_PATH_SUFFIX = "/.ssh/id_rsa";
    /** Default encryption algorithm. */
    const DEFAULT_HTTP_SIGN_ALGO = 'RSA-SHA256';
    /** Default libcurl options. */
    const DEFAULT_CURL_OPTS = array(CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1,
        CURLOPT_SSL_VERIFYPEER, 0);
    /** Maximum number of bytes to read from private key file. */
    const MAXIMUM_PRIV_KEY_SIZE = 51200;
    /** Templated header used for HTTP signature authentication. */
    const AUTH_HEADER = 'Signature keyId="/%s/keys/%s",algorithm="%s",signature="%s"';

    // Properties
    protected $endpoint = null;
    protected $login = null;
    protected $keyid = null;
    protected $algo = null;
    protected $privateKeyContents = null;
    protected $curlopts = null;

    /**
     * Constructor that will accept explicit parameters for building a Manta
     * client or default to environment variable settings when set to null.
     *
     * @since 2.0.0
     * @api
     *
     * @param string|null endpoint            Manta endpoint to use for requests (e.g. https://us-east.manta.joyent.com)
     * @param string|null login               Manta login
     * @param string|null keyid               Manta keyid
     * @param string|null privateKeyContents  Client SSH private key
     * @param string|null algo                Algorithm to use for signatures; valid values are RSA-SHA1, RSA-SHA256, DSA-SHA
     * @param array|null  curlopts            Additional curl options to set for requests
     */
    public function __construct(
        $endpoint = null,
        $login = null,
        $keyid = null,
        $privateKeyContents = null,
        $algo = null,
        $curlopts = null
    ) {
        $this->endpoint = self::paramEnvOrDefault(
            $endpoint,
            self::MANTA_URL_ENV_KEY,
            self::DEFAULT_MANTA_URL,
            "endpoint"
        );

        $this->login = self::paramEnvOrDefault(
            $login,
            self::MANTA_USER_ENV_KEY,
            null,
            "login"
        );

        $this->keyid = self::paramEnvOrDefault(
            $keyid,
            self::MANTA_KEY_ID_ENV_KEY,
            null,
            "keyid"
        );

        if (!is_null($privateKeyContents) && !empty($privateKeyContents)) {
            $this->privateKeyContents = $privateKeyContents;
        } else {
            $keyPath = self::paramEnvOrDefault(
                $privateKeyContents,
                self::MANTA_KEY_PATH_ENV_KEY,
                getenv('HOME') . self::DEFAULT_MANTA_KEY_PATH_SUFFIX,
                "priv_key"
            );
            $contents = file_get_contents(
                $keyPath,
                false,
                null,
                null,
                self::MAXIMUM_PRIV_KEY_SIZE
            );
            $this->privateKeyContents = $contents;
        }

        $this->algo = self::paramEnvOrDefault(
            $algo,
            null,
            self::DEFAULT_HTTP_SIGN_ALGO,
            "algo"
        );
        $this->curlopts = self::paramEnvOrDefault(
            $curlopts,
            null,
            self::DEFAULT_CURL_OPTS,
            "curlopts"
        );
    }

    /**
     * Parses a given constructor parameter and determines if we should use
     * the passed value, the associated environment variable's value or the
     * default value for a given parameter.
     *
     * @param  string|array|null $argValue the value from the constructor's parameter
     * @param  string|null       $envKey   the name of the associated environment variable
     * @param  string|array|null $default  the default value of the parameter
     * @param  string|null       $argName  the name of the parameter for debugging
     * @return string|array                the value chosen based on the inputs
     */
    protected static function paramEnvOrDefault(
        $argValue,
        $envKey,
        $default = null,
        $argName = null
    ) {
        if (!is_null($argValue) && !empty($argValue)) {
            return $argValue;
        }

        if (!is_null($envKey)) {
            $envValue = getenv($envKey);

            if (!is_null($envValue) && !empty($envValue)) {
                return $envValue;
            }
        }

        if (!is_null($default) && (!empty($default) || !is_string($default))) {
            return $default;
        }

        $msg = "You must set the [$argName] argument explicitly or set the " .
            "environment variable [$envKey]";
        throw new \InvalidArgumentException($msg);
    }

    /**
     * Method to generate the encoded portion of the Authorization request
     * header for signing requests.
     *
     * @see https://datatracker.ietf.org/doc/draft-cavage-http-signatures/ HTTP Signatures RFC Proposal
     *
     * @param string $timestamp Data to encrypt for signature (typically timestamp and other parameters)
     *
     * @return string      Fully encoded authorization header
     */
    protected function getAuthorization($timestamp)
    {
        $pkeyid = openssl_get_privatekey($this->privateKeyContents);
        $sig = '';
        $dateString = sprintf('date: %s', $timestamp);
        openssl_sign($dateString , $sig, $pkeyid, $this->algo);
        $sig = base64_encode($sig);
        $algo = strtolower($this->algo);

        return sprintf(self::AUTH_HEADER, $this->login, $this->keyid, $algo, $sig);
    }

    /**
     * Method that executes a REST service call using a selectable verb.
     *
     * @param  string   $method              HTTP method (GET, POST, PUT, DELETE)
     * @param  string   $path                Service portion of URL
     * @param  array    $headers             Additional HTTP headers to send with request
     * @param  string|resource|StreamInterface
     *                  $data                Data to send with PUT or POST requests
     * @param  boolean  $throwErrorOnFailure When set to true, HTTP response codes greater
     *                                       than 299 will trigger a MantaException
     * @return Response $result              HTTP response object
     * @throws MantaException                thrown when we have an IO issue with the network
     */
    protected function execute(
        $method,
        $path,
        $headers = array(),
        $data = null,
        $throwErrorOnFailure = true
    ) {
        // Make sure that the path is in valid UTF-8
        mb_check_encoding($path, 'UTF-8');

        // We remove redundant leading slashes, so our URLs look clean
        $withLeadingSlash = ltrim(str_replace('//', '/', $path), '/');

        $stack = new HandlerStack();
        $stack->setHandler(new CurlHandler());

        $stack->push(Middleware::mapRequest(function (RequestInterface $request) {
            $timestamp = gmdate('r');
            $authorization = $this->getAuthorization($timestamp);

            return $request->withHeader('Date', $timestamp)
                           ->withHeader('Authorization', $authorization);
        }));

        $params = [
            'headers'  => $headers,
            'base_uri' => $this->endpoint,
            'timeout'  => 200,
            'version'  => '1.1',
            'handler'  => $stack
        ];

        $client = new Client($params);

        $options = [
            'proxy'   => 'tcp://127.0.0.1:8888',
            'verify'  => false
        ];

        if (!is_null($data)) {
            $options['body'] = $data;
        }

        $res = $client->request($method, $withLeadingSlash, $options);

        if ($throwErrorOnFailure && $res->getStatusCode() > 299) {
            $jsonDetail = null;

            try
            {
                $jsonDetail = (string)$res->getBody();
            } catch (\Exception $e)
            {
                // Do nothing. If we can't get the details, then we just pass null
            }

            throw new MantaException(
                $res->getReasonPhrase(),
                $res->getStatusCode(),
                $jsonDetail);
        }

        return $res;
    }

    /**
     * Utility method that parses a newline-delimited list of JSON objects into
     * an array.
     *
     * @param  string $data JSON text
     * @return array        JSON data as a PHP array
     */
    protected function parseJSONList($data)
    {
        $retval = array();
        $items = explode("\n", $data);
        foreach ($items as $item) {
            $item_decode = json_decode($item, true);
            if (!empty($item_decode)) {
                $retval[] = $item_decode;
            }
        }

        return $retval;
    }

    /**
     * Utility method used to parse a newline-delimited list of strings into an
     * array with trimming.
     *
     * @param  string $data newline-delimited list of strings
     * @return array        array of strings
     */
    protected function parseTextList($data)
    {
        $retval = array();
        $items = explode("\n", $data);
        foreach ($items as $item) {
            $item = trim($item);
            if (!empty($item)) {
                $retval[] = $item;
            }
        }

        return $retval;
    }

    public function exists($path)
    {
        $result = $this->execute('HEAD', $path, array(), null, false);
        return $result->getStatusCode() == 200;
    }

    /**
     * Creates a new directory in Manta.
     *
     * @see http://apidocs.joyent.com/manta/api.html#PutDirectory
     * @since 2.0.0
     * @api
     *
     * @param  string  $directory      Name of directory
     * @param  boolean $make_parents   Ensure parent directories exist
     *
     * @return array                   array of request header values
     */
    public function putDirectory($directory, $make_parents = false)
    {
        $headers = array(
            'Content-Type' => 'application/json; type=directory'
        );

        $resultHeaders = array();

        if ($make_parents) {
            $parents = explode('/', $directory);
            $directoryTree = '';

            $resultHeaders['all_headers'] = array();

            foreach ($parents as $parent) {
                $directoryTree .= $parent . '/';

                if (!self::canCreateDirectoryAtPath($directoryTree)) {
                    continue;
                }

                $result = $this->execute('PUT', $directoryTree, $headers);
                $resultHeaders['all_headers'][] = $result->getHeaders();
            }
        } else {
            $result = $this->execute('PUT', $directory, $headers);
            $resultHeaders['headers'] = $result->getHeaders();
        }

        return $resultHeaders;
    }

    /**
     * Checks to see if you can create a directory at a given path using known
     * rules for directory structures on Manta.
     *
     * @param string $directory path to directory to create
     * @return boolean          TRUE if you can create the directory at the path
     */
    private static function canCreateDirectoryAtPath($directory)
    {
        return !empty($directory) && !self::isRootOrFirstLevel($directory);
    }

    /**
     * Checks to see if a given directory is the root directory or the very
     * first directory from the root.
     *
     * @param string $directory directory path
     * @return boolean TRUE if directory is root or one level under
     */
    private static function isRootOrFirstLevel($directory)
    {
        if ($directory == '/') {
            return true;
        }

        $count = 0;
        $length = strlen($directory);

        // Exit now because we aren't a slash and there are no more characters
        if ($length == 1) {
            return false;
        }

        for($i = 1; $i < $length; $i++) {
            if (substr($directory, $i, 1) == '/') {
                $count++;

                if ($count > 1) {
                    break;
                }
            }
        }

        return $count == 1;
    }

    /**
     * Lists the contents of a directory on the remote Manta filesystem.
     *
     * @see http://apidocs.joyent.com/manta/api.html#ListDirectory
     * @since 2.0.0
     * @api
     *
     * @param string $directory   Name of directory
     *
     * @return array with 'headers' and 'data' elements where 'data' contains the list of items
     */
    public function listDirectory($directory)
    {
        $headers = array(
            'Content-Type' => 'application/x-json-stream; type=directory'
        );
        $response = $this->execute('GET', $directory, $headers, null, true);
        $body = $response->getBody();

        try
        {
            $responseJson = (string)$body;
            $result = array (
                'headers' => $response->getHeaders(),
                'data'    => $this->parseJSONList($responseJson)
            );

            return $result;
        } finally
        {
            $body->close();
        }
    }

    /**
     * Deletes a single directory or multiple directories from the remote
     * Manta filesystem.
     *
     * @see http://apidocs.joyent.com/manta/api.html#DeleteDirectory
     * @since 2.0.0
     * @api
     *
     * @param  string  $directory   Name of directory
     * @param  boolean $recursive   Recurse down directory wiping all children
     *
     * @return array                array of request header values
     */
    public function deleteDirectory($directory, $recursive = false)
    {
        $results = array();


        if ($recursive) {
            $items = $this->listDirectory($directory);

            $results['all_headers'] = array();

            foreach ($items['data'] as $item) {
                if (!empty($item['type'])) {
                    /** @var Response|null $response */
                    $response = null;

                    if ('directory' == $item['type']) {
                        $response = $this->deleteDirectory("{$directory}/{$item['name']}", true);
                    } elseif ('object' == $item['type']) {
                        $response = $this->deleteObject($item['name'], $directory);
                    }

                    if (is_null($response)) {
                        continue;
                    }

                    if (array_key_exists('headers', $response)) {
                        $results['all_headers'][] = $response['headers'];
                    } else if (array_key_exists('all_headers', $response)) {
                        $results['all_headers'] = array_merge($results['all_headers'], $response['all_headers']);
                    }
                }
            }
        }

        $response = $this->execute('DELETE', $directory);

        if (array_key_exists('all_headers', $results)) {
            $results['all_headers'][] = $response->getHeaders();
        } else {
            $results['headers'] = $response->getHeaders();
        }

        return $results;
    }

    /**
     *
     * @see http://apidocs.joyent.com/manta/api.html#PutObject
     * @since 2.0.0
     * @api
     *
     * @param data            Data to store
     * @param string $object  Name of object
     * @param array  $headers Additional headers; see documentation for valid values
     *
     * @return array          Array containing details of the PUT response
     */
    public function putObject($data, $object, $headers = array())
    {
        if (is_string($data)) {
            $headers['Content-MD5'] = base64_encode(md5($data, true));
        }
        // TODO: Figure out how to performantly handle MD5's for streams

        $response = $this->execute('PUT', $object, $headers, $data);

        return array(
            'headers' => $response->getHeaders()
        );
    }

    /**
     *
     * @see http://apidocs.joyent.com/manta/api.html#GetObject
     * @since 2.0.0
     * @api
     *
     * @param string $object        Name of object
     * @param string|null directory     Name of directory
     *
     * @return array with 'headers' and 'data' elements
     */
    public function getObjectAsString($object)
    {
        $response = $this->execute('GET', $object, null, null, true);
        $body = $response->getBody();

        try
        {
            $result = array(
                'headers' => $response->getHeaders(),
                'data' => (string)$body
            );

            return $result;
        } finally
        {
            $body->close();
        }
    }

    /**
     *
     * @see http://apidocs.joyent.com/manta/api.html#GetObject
     * @since 2.0.0
     * @api
     *
     * @param string $object        Name of object
     * @param string|null directory     Name of directory
     *
     * @return array with 'headers' and 'data' elements
     */
    public function getObjectAsStream($object)
    {
        $response = $this->execute('GET', $object, null, null, true);

        return array(
            'headers' => $response->getHeaders(),
            'stream' => $response->getBody()
        );
    }

    /**
     *
     * @see http://apidocs.joyent.com/manta/api.html#DeleteObject
     * @since 2.0.0
     * @api
     *
     * @param string $object        Name of object
     * @param string|null $directory     Name of directory
     *
     * @return boolean TRUE on success
     */
    public function deleteObject($object, $directory = null)
    {
        $objpath = !empty($directory) ? "{$directory}/{$object}" : $object;
        $result = $this->execute('DELETE', "{$objpath}");
        return $result;
    }

    /**
     *
     * @see http://apidocs.joyent.com/manta/api.html#PutSnapLink
     * @since 2.0.0
     * @api
     *
     * @param string $link          Link
     * @param string $source        Path to source object
     *
     * @return boolean TRUE on success
     */
    public function putSnapLink($link, $source)
    {
        $headers = array(
            'Content-Type' => 'application/json; type=link',
            'Location'     => $source,
        );
        $result = $this->execute('PUT', "{$link}", $headers);
        return true;
    }

    /**
     *
     * @see http://apidocs.joyent.com/manta/api.html#CreateJob
     * @since 2.0.0
     * @api
     *
     * @param string $name      Name of job
     * @param array $phases    Array of MantaJobPhase objects
     *
     * @return array with 'headers' and 'data' elements
     */
    public function createJob($name, $phases)
    {
        $headers = array(
            'Content-Type' => 'application/json'
        );
        $data = json_encode($phases);
        $result = $this->execute('POST', "jobs", $headers, $data, true);

        // Extract the job ID if it was returned
        if (!empty($result['headers']['Location'])) {
            $result['headers']['job_id'] = array_pop(explode('/', $result['headers']['Location']));
        }

        return $result;
    }

    /**
     * @see http://apidocs.joyent.com/manta/api.html#AddJobInputs
     * @since 2.0.0
     * @api
     *
     * @param string job_id    Job id returned by CreateJob
     * @param array inputs    Array of object names to use as inputs
     *
     * @return boolean TRUE on success
     */
    public function addJobInputs($job_id, $inputs)
    {
        $headers = array(
            'Content-Type' => 'text/plain'
        );
        $data = implode("\n", $inputs);
        $result = $this->execute('POST', "jobs/{$job_id}/live/in", $headers, $data);
        return $result;
    }

    /**
     * @see http://apidocs.joyent.com/manta/api.html#EndJobInput
     * @since 2.0.0
     * @api
     *
     * @param string $job_id    Job id returned by CreateJob
     *
     * @return boolean TRUE on success
     */
    public function endJobInput($job_id)
    {
        $result = $this->execute('POST', "jobs/{$job_id}/live/in/end");
        return $result;
    }

    /**
     * @see http://apidocs.joyent.com/manta/api.html#CancelJob
     * @since 2.0.0
     * @api
     *
     * @param string $job_id    Job id returned by CreateJob
     *
     * @return boolean TRUE on success
     */
    public function cancelJob($job_id)
    {
        $result = $this->execute('POST', "jobs/{$job_id}/live/cancel");
        return $result;
    }

    /**
     * @see http://apidocs.joyent.com/manta/api.html#ListJobs
     * @since 2.0.0
     * @api
     *
     * @return array with 'headers' and 'data' elements where 'data' contains the list of items
     */
    public function listJobs()
    {
        $retval = array();
        $result = $this->execute('GET', "jobs", null, null, true);

        $retval['headers'] = $result['headers'];
        $retval['data'] = $this->parseJSONList($result['data']);

        return $retval;
    }

    /**
     * @see http://apidocs.joyent.com/manta/api.html#GetJob
     * @since 2.0.0
     * @api
     *
     * @param string $job_id    Job id returned by CreateJob
     *
     * @return object Job container object
     */
    public function getJob($job_id)
    {
        $result = $this->execute('GET', "jobs/{$job_id}/live/status", null, null, true);
        $retval = json_decode($result['data']);
        return $retval;
    }

    /**
     * @see http://apidocs.joyent.com/manta/api.html#GetJobOutput
     * @since 2.0.0
     * @api
     *
     * @param string $job_id    Job id returned by CreateJob
     *
     * @return array with 'headers' and 'data' elements where 'data' contains the list of output objects
     */
    public function getJobOutput($job_id)
    {
        $retval = array();
        $result = $this->execute('GET', "jobs/{$job_id}/live/out", null, null, true);

        $retval['headers'] = $result['headers'];
        $retval['data'] = $this->parseTextList($result['data']);

        return $retval;
    }

    /**
     * @see http://apidocs.joyent.com/manta/api.html#GetJobInput
     * @since 2.0.0
     * @api
     *
     * @param string $job_id    Job id returned by CreateJob
     *
     * @return array with 'headers' and 'data' elements where 'data' contains the list of input objects
     */
    public function getJobInput($job_id)
    {
        $retval = array();
        $result = $this->execute('GET', "jobs/{$job_id}/live/in", null, null, true);

        $retval['headers'] = $result['headers'];
        $retval['data'] = $this->parseTextList($result['data']);

        return $retval;
    }

    /**
     * @see http://apidocs.joyent.com/manta/api.html#GetJobFailures
     * @since 2.0.0
     * @api
     *
     * @param string $job_id    Job id returned by CreateJob
     *
     * @return array with 'headers' and 'data' elements where 'data' contains the list of error objects
     */
    public function getJobFailures($job_id)
    {
        $retval = array();
        $result = $this->execute('GET', "jobs/{$job_id}/live/fail", null, null, true);

        $retval['headers'] = $result['headers'];
        $retval['data'] = $this->parseTextList($result['data']);

        return $retval;
    }

    /**
     * @see http://apidocs.joyent.com/manta/api.html#GetJobErrors
     * @since 2.0.0
     * @api
     *
     * @param string $job_id    Job id returned by CreateJob
     *
     * @return array with 'headers' and 'data' elements where 'data' contains the errors
     */
    public function getJobErrors($job_id)
    {
        $retval = array();
        $result = $this->execute('GET', "jobs/{$job_id}/live/err", null, null, true);

        $retval['headers'] = $result['headers'];
        $retval['data'] = $this->parseJSONList($result['data']);

        return $retval;
    }
}
