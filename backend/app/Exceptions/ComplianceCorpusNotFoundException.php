<?php

namespace App\Exceptions;

use Exception;

class ComplianceCorpusNotFoundException extends Exception
{
    public function __construct(string $message = 'Compliance corpus resource not found.')
    {
        parent::__construct($message, 404);
    }
}
