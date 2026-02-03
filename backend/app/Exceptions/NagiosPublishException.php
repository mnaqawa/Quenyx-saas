<?php

namespace App\Exceptions;

class NagiosPublishException extends \Exception
{
    /** @var string[] */
    public array $validationErrors;

    public function __construct(string $message, array $validationErrors = [])
    {
        parent::__construct($message);
        $this->validationErrors = $validationErrors;
    }
}
