<?php

namespace Goldnead\Suppression\Gate;

use Goldnead\Suppression\Contracts\Gate;
use Goldnead\Suppression\Exceptions\SuppressionCheckFailed;
use Goldnead\Suppression\Models\Suppression;
use Goldnead\Suppression\Support\EmailNormalizer;
use Throwable;

/**
 * The gate, answered from the `suppressions` table.
 *
 * Every query here goes through `visibleTo()` + `active()`, which is what makes
 * a global row (`brand_id = 0`) bite in every brand while a brand-scoped one
 * stays where it was written.
 *
 * On failure it throws. See `Goldnead\Suppression\Contracts\Gate` for why that
 * is the whole point, and for why a segment resolver two files away is right to
 * do the exact opposite.
 */
class DatabaseGate implements Gate
{
    public function isSuppressed(string $email, ?int $brandId = null): bool
    {
        $normalized = EmailNormalizer::normalize($email);

        if ($normalized === null) {
            // An address that normalizes to nothing cannot be mailed and cannot
            // be looked up. Refusing is the closed answer.
            return true;
        }

        try {
            return Suppression::query()
                ->visibleTo($brandId)
                ->active()
                ->forEmail($normalized)
                ->exists();
        } catch (Throwable $e) {
            throw SuppressionCheckFailed::from($e);
        }
    }

    public function suppressedAmong(iterable $emails, ?int $brandId = null): array
    {
        $normalized = EmailNormalizer::normalizeMany($emails);

        if ($normalized === []) {
            return [];
        }

        try {
            // One query per batch, not one per recipient. The chunked audience
            // loop this feeds used to run a lookup per subscriber.
            $hits = Suppression::query()
                ->visibleTo($brandId)
                ->active()
                ->whereIn('email_normalized', $normalized)
                ->pluck('email_normalized')
                ->all();
        } catch (Throwable $e) {
            throw SuppressionCheckFailed::from($e);
        }

        return array_fill_keys($hits, true);
    }
}
