<?php

namespace Goldnead\Suppression\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The gate could not establish whether an address is blocked.
 *
 * Thrown instead of returning false. A caller that catches this and sends
 * anyway has converted a fail-closed gate into a fail-open one, which is the
 * single defect this package exists to prevent.
 */
class SuppressionCheckFailed extends RuntimeException
{
    public static function from(Throwable $previous): self
    {
        return new self(
            'The suppression gate could not be queried, so no send may proceed: '.$previous->getMessage(),
            0,
            $previous
        );
    }
}
