<?php

namespace App\Services\Compliance\Corpus;

use RuntimeException;

class ComplianceCorpusImportException extends RuntimeException
{
    /**
     * @param list<string> $errors
     */
    public static function validationFailed(array $errors): self
    {
        return new self('Corpus import validation failed: '.implode('; ', $errors));
    }

    public static function invalidPayload(string $message): self
    {
        return new self($message);
    }
}
