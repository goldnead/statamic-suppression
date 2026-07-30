<?php

namespace Goldnead\Suppression\Exceptions;

use RuntimeException;

/**
 * The ordinary release path was pointed at a complaint.
 *
 * Complaints are releasable — that was decided — but not here and not by
 * accident. A stray click on a filtered list must never lift the one block with
 * regulatory weight, so `release()` refuses and the deliberate path
 * `releaseComplaint()` exists beside it, with an audit trail attached.
 */
class ComplaintReleaseRefused extends RuntimeException
{
    public static function for(string $email): self
    {
        return new self(
            "[{$email}] is suppressed as a complaint and cannot be released through the ordinary path. ".
            'Use releaseComplaint(), which requires an actor and a stated reason.'
        );
    }
}
