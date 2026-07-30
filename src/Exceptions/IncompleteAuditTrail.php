<?php

namespace Goldnead\Suppression\Exceptions;

use RuntimeException;

/**
 * A deliberate complaint release arrived without the record that makes it
 * defensible.
 *
 * Three fields turn "someone can be mailed again" into "someone decided this":
 * who, when, and on what stated grounds. Two of them come from the caller, and
 * a release without them is refused rather than logged as incomplete — an audit
 * trail with holes in it is worse than none, because it looks like one.
 */
class IncompleteAuditTrail extends RuntimeException
{
    public static function missingActor(string $email): self
    {
        return new self(
            "Releasing the complaint suppression for [{$email}] requires an actor. ".
            'It must be the authenticated user, taken from the request or the console — never from a form field.'
        );
    }

    public static function reasonTooShort(string $email, int $given, int $required): self
    {
        return new self(
            "Releasing the complaint suppression for [{$email}] requires a stated reason of at least ".
            "{$required} characters; {$given} were given. The reason is the part that has to still make sense ".
            'to somebody reading it a year from now.'
        );
    }
}
