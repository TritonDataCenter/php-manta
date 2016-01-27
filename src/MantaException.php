<?php namespace Joyent\Manta;

class MantaException extends \Exception
{
    /** @var integer HTTP error number */
    public $serverCode;
    /** @var string error message from Manta */
    public $serverMessage;
    /** @var string unique request id used for debugging */
    public $requestId;
    /** @var null|string path associated with error */
    public $path;

    /**
     * MantaException constructor.
     *
     * @param string  $message     HTTP error message
     * @param integer $errorNo     HTTP error number
     * @param string  $requestId   UUID request id
     * @param string  $path        Optional path to where the error occurred
     * @param string  $jsonDetails Raw JSON data
     */
    public function __construct(
        $message,
        $errorNo,
        $requestId,
        $path = null,
        $jsonDetails = null
    ) {
        $details = null;

        if (!empty($jsonDetails)) {
            try {
                $errorDetails = json_decode($jsonDetails);
                $this->serverCode = $errorDetails->{'code'};
                $this->serverMessage = $errorDetails->{'message'};
                $this->path = $path;
                $this->requestId = $requestId;

                $pathDetails = empty($path) ? '' : "[{$path}]";

                $details = sprintf(
                    '[%s] %s %s (%s)',
                    $this->serverCode,
                    $this->serverMessage,
                    $pathDetails,
                    $this->requestId
                );
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
