<?php namespace Joyent\Manta;
/**
 * This is a library for accessing the Joyent Manta Services via
 * the public or the private cloud.
 */

use Ramsey\Uuid\Uuid;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\StreamWrapper;
use Psr\Http\Message\RequestInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Client;
use GuzzleHttp\Middleware;
use Psr\Http\Message\StreamInterface;

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
    /** Environment variable indicating the HTTP handler implementation. */
    const MANTA_HTTP_HANDLER_KEY = 'MANTA_HTTP_HANDLER';
    /** Environment variable indicating the timeout for HTTP requests. */
    const MANTA_TIMEOUT_KEY = 'MANTA_TIMEOUT';
    /** Environment variable indicating to turn off TLS key verification. */
    const MANTA_TLS_INSECURE_KEY = 'MANTA_TLS_INSECURE';

    /** Default value for Manta REST endpoint. */
    const DEFAULT_MANTA_URL = 'https://us-east.manta.joyent.com:443';
    /** Default path suffix for private encryption key. */
    const DEFAULT_MANTA_KEY_PATH_SUFFIX = "/.ssh/id_rsa";
    /** Default encryption algorithm. */
    const DEFAULT_HTTP_SIGN_ALGO = 'RSA-SHA256';
    /** Default HTTP timeout. */
    const DEFAULT_TIMEOUT = 20.0;
    /** Default HTTP handler class. */
    const DEFAULT_HTTP_HANDLER = 'GuzzleHttp\Handler\StreamHandler';

    /** Maximum number of bytes to read from private key file. */
    const MAXIMUM_PRIV_KEY_SIZE = 51200;
    /** Templated header used for HTTP signature authentication. */
    const AUTH_HEADER = 'Signature keyId="/%s/keys/%s",algorithm="%s",signature="%s"';

    // Properties

    /** @var null|string Root endpoint to make HTTP requests against */
    protected $endpoint = null;
    /** @var null|string  Manta primary account name */
    protected $login = null;
    /** @var null|string RSA key fingerprint */
    protected $keyid = null;
    /** @var null|string HTTP signature algorithm name*/
    protected $algo = null;
    /** @var null|string Contents of RSA private key used for HTTP signing */
    protected $privateKeyContents = null;
    /** @var null|float Timeout in seconds for HTTP calls */
    protected $timeout = null;
    /** @var null|HandlerStack HTTP implementation to use for making calls */
    protected $handlerStack = null;
    /** @var null|boolean Flag indicating if we should validate TLS keys  */
    protected $insecureTlsKey = null;
    /** @var null|Client HTTP client instance  */
    protected $client = null;

    /**
     * Constructor that will accept explicit parameters for building a Manta
     * client or default to environment variable settings when set to null.
     *
     * @since 2.0.0
     * @api
     *
     * @param string|null  endpoint            Manta endpoint to use for requests (e.g. https://us-east.manta.joyent.com)
     * @param string|null  login               Manta login
     * @param string|null  keyid               Manta keyid
     * @param string|null  privateKeyContents  Client SSH private key
     * @param string|null  algo                Algorithm to use for signatures; valid values are RSA-SHA1, RSA-SHA256, DSA-SHA
     * @param float|null   timeout             Float describing the timeout of the request in seconds. Use 0 to wait indefinitely (the default behavior)
     * @param string|null  handler             Name of the Guzzle handler class to use for making HTTP calls (e.g. GuzzleHttp\Handler\CurlHandler)
     * @param boolean|null insecureTlsKey       When true we don't verify TLS keys
     */
    public function __construct(
        $endpoint = null,
        $login = null,
        $keyid = null,
        $privateKeyContents = null,
        $algo = null,
        $timeout = null,
        $handler = null,
        $insecureTlsKey = null
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

        $this->timeout = self::paramEnvOrDefault(
            $timeout,
            self::MANTA_TIMEOUT_KEY,
            self::DEFAULT_TIMEOUT,
            "timeout"
        );

        $handlerClass = self::paramEnvOrDefault(
            $handler,
            self::MANTA_HTTP_HANDLER_KEY,
            self::DEFAULT_HTTP_HANDLER,
            "handler"
        );

        $this->handlerStack = $this->buildHandlerStack($handlerClass);

        $verifyTlsKeyValue = self::paramEnvOrDefault(
            $insecureTlsKey,
            self::MANTA_TLS_INSECURE_KEY,
            false,
            "verify TLS key"
        );

        // Value passed to us may have been an integer
        $this->insecureTlsKey = (boolean)$verifyTlsKeyValue;

        // Build the HTTP client once, so that we don't have to do it each call
        $this->client = $this->buildHttpClient();
    }

    /**
     * Parses a given constructor parameter and determines if we should use
     * the passed value, the associated environment variable's value or the
     * default value for a given parameter.
     *
     * @param  string|array|float|boolean|null
     *                           $argValue the value from the constructor's parameter
     * @param  string|null       $envKey   the name of the associated environment variable
     * @param  string|array|float|boolean|null
     *                           $default  the default value of the parameter
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
     * Creates a HandlerStack based off of the supplied configuration.
     *
     * @param string $handlerClass full class name to use for the HTTP handler implementation
     * @return HandlerStack configured HandlerStack implementation
     */
    protected function buildHandlerStack($handlerClass)
    {
        $stack = new HandlerStack();
        $stack->setHandler(new $handlerClass());

        $stack->push(Middleware::mapRequest(function (RequestInterface $request) {
            $timestamp = gmdate('r');
            $authorization = $this->getAuthorization($timestamp);

            return $request
                ->withHeader('Date', $timestamp)
                ->withHeader('Authorization', $authorization)
                ->withHeader('x-request-id', (string)Uuid::uuid4());
        }));

        return $stack;
    }

    /**
     * Creates a configured HTTP client instance.
     *
     * @return Client configured HTTP client
     */
    protected function buildHttpClient() {
        $params = [
            'base_uri' => $this->endpoint,
            'timeout'  => $this->timeout,
            'version'  => '1.1',
            'handler'  => $this->handlerStack
        ];

        return new Client($params);
    }

    /**
     * Method to generate the encoded portion of the Authorization request
     * header for signing requests.
     *
     * @see https://datatracker.ietf.org/doc/draft-cavage-http-signatures/ HTTP Signatures RFC Proposal
     *
     * @param  string $timestamp Data to encrypt for signature (typically timestamp and other parameters)
     * @return string            Fully encoded authorization header
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

        $options = [
            'headers'         => $headers,
            'connect_timeout' => $this->timeout,
            'verify'          => !$this->insecureTlsKey,
            // we have our own error handling logic, so we don't need this done for us
            'http_errors'     => false
        ];

        $proxy = getenv('http_proxy');

        if ($proxy) {
            $options['proxy'] = "tcp://${proxy}";
        }

        if (!is_null($data)) {
            $options['body'] = $data;
        }

        $res = $this->client->request($method, $withLeadingSlash, $options);

        if ($throwErrorOnFailure && $res->getStatusCode() > 399) {
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
                $res->getHeaderLine('x-request-id'),
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

    /**
     * Determines if a given object or directory exists in Manta.
     *
     * @param $path object path to check
     * @return boolean TRUE if object exists, otherwise FALSE
     */
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
                        $response = $this->deleteObject("{$directory}/{$item['name']}");
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
     * Retrieve an object from Manta as a in-memory string.
     * Warning: this function is not memory efficient.
     *
     * @see http://apidocs.joyent.com/manta/api.html#GetObject
     * @since 2.0.0
     * @api
     *
     * @param string $object        Path of object
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
     * Retrieve an object from Manta as a stream.
     * This function is good for general purpose use because it allows you
     * to stream data in a memory efficient manner.
     *
     * @see http://apidocs.joyent.com/manta/api.html#GetObject
     * @since 2.0.0
     * @api
     *
     * @param string $object        Path of object
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
     * Retrieve an object from Manta as a temporary file.
     * This function uses streams to copy a remote file to the local
     * filesystem as a temporary file.
     *
     * Note: this file is not deleted for you.
     *
     * @see http://apidocs.joyent.com/manta/api.html#GetObject
     * @since 2.0.0
     * @api
     *
     * @param string $object        Path of object
     *
     * @return array with 'headers' and 'file' elements
     */
    public function getObjectAsFile($object) {
        $results = $this->getObjectAsStream($object);
        $headers = $results['headers'];
        /** @var StreamInterface $stream stream of Manta object contents */
        $stream = $results['stream'];
        $input = StreamWrapper::getResource($stream);
        $tempDir = sys_get_temp_dir();
        $filePath = tempnam ($tempDir, 'manta-php-');
        $file = fopen($filePath, 'w');

        try {
            stream_copy_to_stream($input, $file);
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }

            if (is_resource($input)) {
                fclose($input);
            }
            $stream->close();
        }

        return array(
            'headers' => $headers,
            'file' => $filePath
        );
    }

    /**
     * Deletes an object from Manta.
     *
     * @see http://apidocs.joyent.com/manta/api.html#DeleteObject
     * @since 2.0.0
     * @api
     *
     * @param string $object        Name of object
     *
     * @return array with 'headers' element
     */
    public function deleteObject($object)
    {
        $response = $this->execute('DELETE', $object);

        return array(
            'headers' => $response->getHeaders()
        );
    }

    /**
     * Creates a remote copy of a file on Manta. This copy does not use any
     * additional disk space and will persist even if the original file
     * is deleted. See the Manta documentation for more details.
     *
     * @see http://apidocs.joyent.com/manta/api.html#PutSnapLink
     * @since 2.0.0
     * @api
     *
     * @param string $link          Link
     * @param string $source        Path to source object
     *
     * @return array with 'headers' element
     */
    public function putSnapLink($source, $link)
    {
        $headers = array(
            'Content-Type' => 'application/json; type=link',
            'Location'     => $source,
        );

        $response = $this->execute('PUT', $link, $headers);

        return array(
            'headers' => $response->getHeaders()
        );
    }

    /**
     *
     * @see http://apidocs.joyent.com/manta/api.html#CreateJob
     * @since 2.0.0
     * @api
     *
     * @param array  $phases    Array of MantaJobPhase objects
     * @param string $name      Name of job
     *
     * @return array with 'headers', 'jobId' and 'headers' elements
     */
    public function createJob($phases, $name = null)
    {
        $headers = array(
            'Content-Type' => 'application/json'
        );

        $body = array(
            'phases' => $phases
        );

        if (!empty($name)) {
            $body['name'] = $name;
        }

        $data = json_encode($body);
        $response = $this->execute('POST', "/{$this->login}/jobs", $headers, $data, true);
        $location = $response->getHeaderLine('location');
        $jobId = self::extractJobIdFromLocation($location);

        return array(
            'jobId'    => $jobId,
            'location' => $location,
            'headers'  => $response->getHeaders()
        );
    }

    /**
     * Extracts the job id from a location path that is returned when creating
     * a new job.
     *
     * @param string $location path to job on the Manta filesystem
     * @return string UUID as a string
     */
    protected static function extractJobIdFromLocation($location)
    {
        if (empty($location)) {
            return null;
        }

        $matches = array();
        preg_match('/^\/.+\/(.+)$/', $location, $matches);

        if (empty($matches) || count($matches) < 2) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Associates files on the Manta filesystem with a job, so that the job
     * can access those files.
     *
     * @see http://apidocs.joyent.com/manta/api.html#AddJobInputs
     * @since 2.0.0
     * @api
     *
     * @param string $job_id    Job id returned by CreateJob
     * @param array  $inputs    Array of object names to use as inputs
     *
     * @return array with 'headers' element
     */
    public function addJobInputs($job_id, $inputs)
    {
        $headers = array(
            'Content-Type' => 'text/plain'
        );
        $data = implode("\n", $inputs);
        $response = $this->execute('POST', "/{$this->login}/jobs/{$job_id}/live/in", $headers, $data);

        return array(
            'headers' => $response->getHeaders()
        );
    }

    /**
     * Runs a job. This method closes the job such that it can't accept new inputs.
     *
     * @see http://apidocs.joyent.com/manta/api.html#EndJobInput
     * @since 2.0.0
     * @api
     *
     * @param string $jobId    Job id returned by CreateJob
     *
     * @return array with 'headers' element
     */
    public function endJobInput($jobId)
    {
        $response = $this->execute('POST', "/{$this->login}/jobs/{$jobId}/live/in/end");

        return array(
            'headers' => $response->getHeaders()
        );
    }

    /**
     * Cancels a job that has not been closed or is currently running.
     *
     * @see http://apidocs.joyent.com/manta/api.html#CancelJob
     * @since 2.0.0
     * @api
     *
     * @param string $jobId    Job id returned by CreateJob
     *
     * @return array with 'headers' element
     */
    public function cancelJob($jobId)
    {
        $response = $this->execute('POST', "/{$this->login}/jobs/{$jobId}/live/cancel");

        return array(
            'headers' => $response->getHeaders()
        );
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
        $result = $this->execute('GET', "/{$this->login}/jobs", null, null, true);

        $retval['headers'] = $result['headers'];
        $retval['data'] = $this->parseJSONList($result['data']);

        return $retval;
    }

    /**
     * Retrieves a job's metadata including its state.
     *
     * @see http://apidocs.joyent.com/manta/api.html#GetJob
     * @since 2.0.0
     * @api
     *
     * @param string $jobId Job id returned by CreateJob
     *
     * @return array        with 'headers' and 'data' elements where 'data' contains the list of output objects
     */
    public function getJob($jobId)
    {
        $response = $this->execute('GET', "/{$this->login}/jobs/{$jobId}/live/status", null, null, true);
        $headers = $response->getHeaders();
        $data = json_decode($response->getBody());

        return array(
            'headers' => $headers,
            'data'    => $data
        );
    }

    /**
     * Retrieves the current state of a job.
     *
     * @see http://apidocs.joyent.com/manta/api.html#GetJob
     * @since 2.0.0
     * @api
     *
     * @param string $jobId Job id returned by CreateJob
     *
     * @return string       state string returned from Manta
     */
    public function getJobState($jobId)
    {
        $response = $this->getJob($jobId);
        assert(array_key_exists('data', $response));

        return $response['data']->{'state'};
    }

    /**
     * Retrieves a live list of all of the objects that have completed
     * processing. There is a hard limit of 100 results, so this method is
     * intended for getting information about the progress of a running job
     * rather than getting a comprehensive list of all of the outputs.
     *
     * @see http://apidocs.joyent.com/manta/api.html#GetJobOutput
     * @since 2.0.0
     * @api
     *
     * @param string $jobId    Job id returned by CreateJob
     *
     * @return array with 'headers' and 'data' elements where 'data' contains the list of output objects
     */
    public function getJobLiveOutputs($jobId)
    {
        $response = $this->execute('GET', "/{$this->login}/jobs/{$jobId}/live/out", null, null, true);

        $headers = $response->getHeaders();
        $data = $this->parseTextList($response->getBody());

        return array(
            'headers' => $headers,
            'data'    => $data
        );
    }

    /**
     * Retrieves a live list of all of the objects that have completed
     * processing. This method is intended for getting a comprehensive list
     * of all of the outputs.
     *
     * @see http://apidocs.joyent.com/manta/api.html#GetObject
     * @since 2.0.0
     * @api
     *
     * @param string $jobId    Job id returned by CreateJob
     *
     * @return array with 'headers' and 'data' elements where 'data' contains the list of output objects
     */
    public function getJobOutputs($jobId)
    {
        $response = $this->execute('GET', "/{$this->login}/jobs/{$jobId}/out.txt", null, null, true);

        $headers = $response->getHeaders();
        $data = $this->parseTextList($response->getBody());

        return array(
            'headers' => $headers,
            'data'    => $data
        );
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
        $result = $this->execute('GET', "/{$this->login}/jobs/{$job_id}/live/in", null, null, true);

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
        $result = $this->execute('GET', "/{$this->login}/jobs/{$job_id}/live/fail", null, null, true);

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
        $result = $this->execute('GET', "/{$this->login}/jobs/{$job_id}/live/err", null, null, true);

        $retval['headers'] = $result['headers'];
        $retval['data'] = $this->parseJSONList($result['data']);

        return $retval;
    }
}
