<?php namespace Joyent\Manta;
/**
 * This is a library for accessing the Joyent Manta Services via
 * the public or the private cloud.
 */

/** We import Exception explicitly because if we don't it confuses phpDocumentor. */
use \Exception;

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
    const DEFAULT_CURL_OPTS = array();
    /** Maximum number of bytes to read from private key file. */
    const MAXIMUM_PRIV_KEY_SIZE = 51200;
    /** Templated header used for HTTP signature authentication. */
    const AUTH_HEADER = 'Authorization: Signature keyId="/%s/keys/%s",algorithm="%s",signature="%s"';

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
     * @param string $data Data to encrypt for signature (typically timestamp and other parameters)
     *
     * @return string      Fully encoded authorization header
     */
    protected function getAuthorization($data)
    {
        $pkeyid = openssl_get_privatekey($this->privateKeyContents);
        $sig = '';
        openssl_sign($data, $sig, $pkeyid, $this->algo);
        $sig = base64_encode($sig);
        $algo = strtolower($this->algo);

        return sprintf(self::AUTH_HEADER, $this->login, $this->keyid, $algo, $sig);
    }

    /**
     * Method that executes a REST service call using a selectable verb.
     *
     * @param  string  $method       HTTP method (GET, POST, PUT, DELETE)
     * @param  string  $url          Service portion of URL
     * @param  array   $headers      Additional HTTP headers to send with request
     * @param  string  $data         Data to send with PUT or POST requests
     * @param  boolean $resp_headers Set to TRUE to return response headers as well as resp data
     * @return array                 Raw resp data is returned on success; if resp_headers is set,
     *                               then an array containing 'headers' and 'data' elements is returned
     * @throws Exception             thrown when an unknown exception state happens
     * @throws MantaException        thrown when we have an IO issue with the network
     */
    protected function execute(
        $method,
        $url,
        $headers = array(),
        $data = null,
        $resp_headers = false
    ) {
        $retval = false;

        // Prepare authorization headers
        if (!$headers) {
            $headers = array();
        }
        $headers[] = $this->getAuthorization($headers[] = 'date: ' . gmdate('r'));

        // Create a new cURL resource
        $ch = curl_init();
        if ($ch) {
            // Set required curl options
            $curlopts = array(
                CURLOPT_HEADER => $resp_headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_URL => "{$this->endpoint}/{$this->login}/{$url}",
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
            );
            if ($data) {
                $curlopts[CURLOPT_POSTFIELDS] = $data;
            }

            // Merge extra options, preventing overwrite of required options
            foreach ($this->curlopts as $opt_key => $opt_value) {
                if (!isset($curlopts[$opt_key])) {
                    $curlopts[$opt_key] = $opt_value;
                }
            }

            // Execute the request and cleanup
            try {
                curl_setopt_array($ch, $curlopts);
                $resp = curl_exec($ch);
                if (false !== $resp) {
                    // Pull info from response and see if we had an error
                    $info = curl_getinfo($ch);
                    if ($info['http_code'] >= 400) {
                        $msg = 'API call failed, no information returned';

                        // Try to extract error info from response
                        $error = json_decode($resp, true);
                        if ($error && isset($error['code']) && isset($error['message'])) {
                            $msg = $error['code'] . ': ' . $error['message'];
                        }

                        throw new MantaException($msg, $info['http_code']);
                    } else {
                        if (!$resp_headers) {
                            $retval = $resp;
                        } else {
                            // Split out response headers into name => value array
                            list($headers, $data) = explode("\r\n\r\n", $resp, 2);
                            $headers = explode("\r\n", $headers);
                            foreach ($headers as $header) {
                                if ('HTTP' == substr($header, 0, 4)) {
                                    continue;
                                }
                                list($name, $value) = explode(':', $header, 2);
                                $retval['headers'][trim($name)] = trim($value);
                            }
                            $retval['data'] = $data;
                        }
                    }
                } else {
                    throw new MantaException(curl_error($ch), curl_errno($ch));
                }
            } catch (Exception $e) {
                // Close and cleanup here since 'finally' blocks aren't available until PHP 5.5
                curl_close($ch);
                throw $e;
            }

            // Close and cleanup
            curl_close($ch);
        } else {
            throw new MantaException('Unable to initialize curl session');
        }

        return $retval;
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
     * Creates a new directory in Manta.
     *
     * @see http://apidocs.joyent.com/manta/api.html#PutDirectory
     * @since 2.0.0
     * @api
     *
     * @param  string  $directory      Name of directory
     * @param  boolean $make_parents   Ensure parent directories exist
     *
     * @return boolean                 TRUE on success
     */
    public function putDirectory($directory, $make_parents = false)
    {
        $headers = array(
            'Content-Type: application/json; type=directory',
        );

        if ($make_parents) {
            $parents = explode('/', $directory);
            $directory = '';
            foreach ($parents as $parent) {
                $directory .= $parent . '/';
                // TODO: Figure out why we aren't using the $result
                $result = $this->execute('PUT', "stor/{$directory}", $headers);
            }
        } else {
            $result = $this->execute('PUT', "stor/{$directory}", $headers);
        }
        return true;
    }

    /**
     *
     *
     * @see http://apidocs.joyent.com/manta/api.html#ListDirectory
     * @since 2.0.0
     * @api
     *
     * @param string $directory   Name of directory
     *
     * @return array with 'headers' and 'data' elements where 'data' contains the list of items
     */
    public function listDirectory($directory = '')
    {
        $retval = array();
        $headers = array(
            'Content-Type: application/x-json-stream; type=directory',
        );
        $result = $this->execute('GET', "stor/{$directory}", $headers, null, true);

        $retval['headers'] = $result['headers'];
        $retval['data'] = $this->parseJSONList($result['data']);

        return $retval;
    }

    /**
     *
     * @see http://apidocs.joyent.com/manta/api.html#DeleteDirectory
     * @since 2.0.0
     * @api
     *
     * @param  string  $directory   Name of directory
     * @param  boolean $recursive   Recurse down directory wiping all children
     *
     * @return TRUE                 on success
     */
    public function deleteDirectory($directory, $recursive = false)
    {
        if ($recursive) {
            $items = $this->listDirectory($directory);
            foreach ($items['data'] as $item) {
                if (!empty($item['type'])) {
                    if ('directory' == $item['type']) {
                        $this->deleteDirectory("{$directory}/{$item['name']}", true);
                    } elseif ('object' == $item['type']) {
                        $this->deleteObject($item['name'], $directory);
                    }
                }
            }
        }

        // TODO: Figure out why we aren't using $result
        $result = $this->execute('DELETE', "stor/{$directory}");
        return true;
    }

    /**
     *
     * @see http://apidocs.joyent.com/manta/api.html#PutObject
     * @since 2.0.0
     * @api
     *
     * @param data          Data to store
     * @param string $object        Name of object
     * @param string|null $directory     Name of directory
     * @param array  $headers       Additional headers; see documentation for valid values
     *
     * @return boolean TRUE on success
     */
    public function putObject($data, $object, $directory = null, $headers = array())
    {
        $headers[] = 'Content-MD5: ' . base64_encode(md5($data, true));
        $objpath = !empty($directory) ? "{$directory}/{$object}" : $object;
        $result = $this->execute('PUT', "stor/{$objpath}", $headers, $data);
        return true;
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
    public function getObject($object, $directory = null)
    {
        $objpath = !empty($directory) ? "{$directory}/{$object}" : $object;
        $result = $this->execute('GET', "stor/{$objpath}", null, null, true);
        return $result;
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
        $result = $this->execute('DELETE', "stor/{$objpath}");
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
            'Content-Type: application/json; type=link',
            "Location: /{$this->login}/stor/{$source}",
        );
        $result = $this->execute('PUT', "stor/{$link}", $headers);
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
            'Content-Type: application/json',
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
            'Content-Type: text/plain',
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
