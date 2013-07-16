<?php
/*! \mainpage
 *
 * This class library is unofficial, unsanctioned, and unsupported by Joyent, and is built based on their public REST API service documentation located here:
 *
 * http://apidocs.joyent.com/manta/api.html
 *
 * The code currently resides on BitBucket here:
 *
 * http://git.octobang.com/php-manta
 */

/**
 * @file
 *
 * Provides a simple class library for accessing the Joyent Manta Services via the REST API
 */

class MantaClient {
  // Properties
  protected $endpoint = NULL;
  protected $login = NULL;
  protected $keyid = NULL;
  protected $algo = NULL;
  protected $priv_key = NULL;
  protected $curlopts = NULL;

  /**
   * Construction
   *
   * @param endpoint  Manta endpoint to use for requests (e.g. https://us-east.manta.joyent.com)
   * @param login     Manta login
   * @param keyid     Manta keyid
   * @param priv_key  Client SSH private key
   * @param algo      Algorithm to use for signatures; valid values are RSA-SHA1, RSA-SHA256, DSA-SHA
   * @param curlopts  Additional curl options to set for requests
   */
  public function __construct($endpoint, $login, $keyid, $priv_key, $algo = 'RSA-SHA256', $curlopts = array()) {
    $this->endpoint = $endpoint;
    $this->login = $login;
    $this->keyid = $keyid;
    $this->algo = $algo;
    $this->priv_key = $priv_key;
    $this->curlopts = $curlopts;
  }

  /**
   * Internal method to generate the encoded portion of the Authorization request header
   *
   * @param data      Data to encrypt for signature
   *
   * @return Fully encoded authorization header
   */
  protected function getAuthorization($data) {
    $pkeyid = openssl_get_privatekey($this->priv_key);
    $sig = '';
    openssl_sign($data, $sig, $pkeyid, $this->algo);
    $sig = base64_encode($sig);
    $algo = strtolower($this->algo);
    return "Authorization: Signature keyId=\"/{$this->login}/keys/{$this->keyid}\",algorithm=\"{$algo}\",signature=\"{$sig}\"";
  }

  /**
   * Internal method to execute REST service call
   *
   * @param method        HTTP method (GET, POST, PUT, DELETE)
   * @param url           Service portion of URL
   * @param headers       Additional HTTP headers to send with request
   * @param data          Data to send with PUT or POST requests
   * @param resp_headers  Set to TRUE to return response headers as well as resp data
   *
   * @return Raw resp data is returned on success; if resp_headers is set, then an array containing 'headers' and 'data' elements is returned
   * @throws Exception on error
   */
  protected function execute($method, $url, $headers = array(), $data = NULL, $resp_headers = FALSE) {
    $retval = FALSE;

    // Prepare authorization headers
    if (!$headers) {
      $headers = array();
    }
    $headers[] = $this->getAuthorization($headers[] = 'date: ' . date('r'));

    // Create a new cURL resource
    $ch = curl_init();
    if ($ch) {
      // Set required curl options
      $curlopts = array(
        CURLOPT_HEADER => $resp_headers,
        CURLOPT_RETURNTRANSFER => TRUE,
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
        if (FALSE !== $resp) {
          // Pull info from response and see if we had an error
          $info = curl_getinfo($ch);
          if ($info['http_code'] >= 400) {
            $msg = 'API call failed, no information returned';

            // Try to extract error info from response
            $error = json_decode($resp, TRUE);
            if ($error && isset($error['code']) && isset($error['message'])) {
              $msg = $error['code'] . ': ' . $error['message'];
            }

            throw new Exception($msg, $info['http_code']);
          }
          else {
            if (!$resp_headers) {
              $retval = $resp;
            }
            else {
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
        }
        else {
          throw new Exception(curl_error($ch), curl_errno($ch));
        }
      }
      catch (Exception $e) {
        // Close and cleanup here since 'finally' blocks aren't available until PHP 5.5
        curl_close($ch);
        throw $e;
      }

      // Close and cleanup
      curl_close($ch);
    }
    else {
      throw new Exception('Unable to initialize curl session');
    }

    return $retval;
  }

  /**
   * http://apidocs.joyent.com/manta/api.html#PutDirectory
   *
   * @param directory     Name of directory
   * @param make_parents  Ensure parent directories exist
   *
   * @return TRUE on success
   * @throws Exception on error
   */
  public function PutDirectory($directory, $make_parents = FALSE) {
    $headers = array(
      'Content-Type: application/json; type=directory',
    );

    if ($make_parents) {
      $parents = explode('/', $directory);
      $directory = '';
      foreach ($parents as $parent) {
        $directory .= $parent . '/';
        $result = $this->execute('PUT', "stor/{$directory}", $headers);
      }
    }
    else {
      $result = $this->execute('PUT', "stor/{$directory}", $headers);
    }
    return TRUE;
  }

  /**
   * http://apidocs.joyent.com/manta/api.html#ListDirectory
   *
   * @param directory   Name of directory
   *
   * @return Array with 'headers' and 'data' elements where 'data' contains the list of items
   * @throws Exception on error
   */
  public function ListDirectory($directory = '') {
    $retval = array();
    $headers = array(
      'Content-Type: application/x-json-stream; type=directory',
    );
    $result = $this->execute('GET', "stor/{$directory}", $headers, NULL, TRUE);
    $retval['headers'] = $result['headers'];
    $retval['data'] = array();

    if (!empty($result['data']) && is_string($result['data'])) {
      $items = explode("\n", $result['data']);
      foreach ($items as $item) {
        $item_decode = json_decode($item, TRUE);
        if (!empty($item_decode)) {
          $retval['data'][] = $item_decode;
        }
      }
    }
    return $retval;
  }

  /**
   * http://apidocs.joyent.com/manta/api.html#DeleteDirectory
   *
   * @param directory   Name of directory
   * @param recursive   Recurse down directory wiping all children
   *
   * @return TRUE on success
   * @throws Exception on error
   */
  public function DeleteDirectory($directory, $recursive = FALSE) {
    if ($recursive) {
      $items = $this->ListDirectory($directory);
      foreach ($items as $item) {
        if ('directory' == $item['type']) {
          $this->DeleteDirectory("{$directory}/{$item['name']}", TRUE);
        }
        elseif ('object' == $item['type']) {
          $this->DeleteObject($item['name'], $directory, TRUE);
        }
      }
    }
    $result = $this->execute('DELETE', "stor/{$directory}");
    return TRUE;
  }

  /**
   * http://apidocs.joyent.com/manta/api.html#PutObject
   *
   * @param data          Data to store
   * @param object        Name of object
   * @param directory     Name of directory
   * @param headers       Additional headers; see documentation for valid values
   *
   * @return TRUE on success
   * @throws Exception on error
   */
  public function PutObject($data, $object, $directory = NULL, $headers = array()) {
    $headers[] = 'Content-MD5: ' . base64_encode(md5($data, TRUE));
    $objpath = !empty($directory) ? "{$directory}/{$object}" : $object;
    $result = $this->execute('PUT', "stor/{$objpath}", $headers, $data);
    return TRUE;
  }

  /**
   * http://apidocs.joyent.com/manta/api.html#GetObject
   *
   * @param object        Name of object
   * @param directory     Name of directory
   *
   * @return Array with 'headers' and 'data' elements
   * @throws Exception on error
   */
  public function GetObject($object, $directory = NULL) {
    $objpath = !empty($directory) ? "{$directory}/{$object}" : $object;
    $result = $this->execute('GET', "stor/{$objpath}", NULL, NULL, TRUE);
    return $result;
  }

  /**
   * http://apidocs.joyent.com/manta/api.html#DeleteObject
   *
   * @param object        Name of object
   * @param directory     Name of directory
   *
   * @return TRUE on success
   * @throws Exception on error
   */
  public function DeleteObject($object, $directory = NULL) {
    $objpath = !empty($directory) ? "{$directory}/{$object}" : $object;
    $result = $this->execute('DELETE', "stor/{$objpath}");
    return $result;
  }

  /**
   * http://apidocs.joyent.com/manta/api.html#PutSnapLink
   *
   * @param link          Link
   * @param source        Path to source object
   *
   * @return TRUE on success
   * @throws Exception on error
   */
  public function PutSnapLink($link, $source) {
    $headers = array(
      'Content-Type: application/json; type=link',
      "Location: /{$this->login}/stor/{$source}",
    );
    $result = $this->execute('PUT', "stor/{$link}", $headers);
    return TRUE;
  }
}
