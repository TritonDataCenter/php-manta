<?php namespace Joyent\Manta;

class MantaException extends \Exception
{
    public $serverCode;
    public $serverMessage;
    public $requestId;

    /**
     * MantaException constructor.
     *
     * @param string  $message     HTTP error message
     * @param integer $errorNo     HTTP error number
     * @param string  $requestId   UUID request id
     * @param string  $jsonDetails Raw JSON data
     */
    public function __construct($message, $errorNo, $requestId, $jsonDetails = null)
    {
        $details = null;

        if (!empty($jsonDetails)) {
            try {
                $errorDetails = json_decode($jsonDetails);
                $this->serverCode = $errorDetails->{'code'};
                $this->serverMessage = $errorDetails->{'message'};
                $this->requestId = $requestId;

                $details = sprintf(
                    '[%s] %s (%s)',
                    $this->serverCode,
                    $this->serverMessage,
                    $this->requestId);
            } catch (\Exception $e) {
                // Ignore any errors, we just let $details stay null
            }
        }

        $fullMessage = null;

        if (is_null($details)) {
            $fullMessage = $message;
        } else {
            $fullMessage = sprintf('%d %s - %s', $errorNo, $message, $details);
        }

        parent::__construct($fullMessage, $errorNo);
    }
}
