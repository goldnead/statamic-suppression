<?php

namespace Goldnead\Suppression\Support;

/**
 * Trim and lowercase, and nothing else.
 *
 * Deliberately identical in behaviour to LeadHub's normalizer of the same name,
 * because the two keys have to agree: a suppression written from a bounce and a
 * subscription written from a sign-up form must land on the same string or the
 * gate misses. It is duplicated rather than imported so this package can be
 * installed without LeadHub — a host that sends notifications and no marketing
 * mail still needs the gate.
 *
 * No Gmail dot-stripping and no plus-address folding. Those rules are correct
 * for exactly one provider and wrong for business mailboxes, and a suppression
 * layer that over-matches blocks people who never bounced.
 */
class EmailNormalizer
{
    public static function normalize(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $value = mb_strtolower(trim($email));

        return $value === '' ? null : $value;
    }

    /**
     * Normalize a list, dropping anything that normalizes to nothing.
     *
     * @param  iterable<mixed>  $emails
     * @return list<string>
     */
    public static function normalizeMany(iterable $emails): array
    {
        $normalized = [];

        foreach ($emails as $email) {
            $value = is_string($email) ? self::normalize($email) : null;

            if ($value !== null) {
                $normalized[$value] = true;
            }
        }

        return array_keys($normalized);
    }
}
