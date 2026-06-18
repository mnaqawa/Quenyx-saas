<?php

namespace App\Services\Compliance\Corpus;

use RuntimeException;

/**
 * Internal sentinel to roll back dry-run import transactions.
 */
class DryRunRollbackException extends RuntimeException
{
}
