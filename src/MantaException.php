<?php namespace Joyent\Manta;

class MantaException extends \Exception
{
    public $serverCode;
    public $serverMessage;

    /**
     * MantaException constructor.
     */
    public function __construct($message, $errorNo, $jsonDetails = null)
    {
        $details = null;

        if (!empty($jsonDetails)) {
            try {
                $errorDetails = json_decode($jsonDetails);
                $this->serverCode = $errorDetails->{'code'};
                $this->serverMessage = $errorDetails->{'message'};

                $details = sprintf(
                    '[%s] %s',
                    $this->serverCode,
                    $this->serverMessage);
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
