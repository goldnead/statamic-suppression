<?php

namespace Goldnead\Suppression\Gate;

use Goldnead\Suppression\Contracts\Gate;
use Goldnead\Suppression\Exceptions\SuppressionCheckFailed;
use Goldnead\Suppression\Models\Suppression;
use Goldnead\Suppression\Support\EmailNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
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
            $suppressed = $this->scoped($brandId)
                ->active()
                ->forEmail($normalized)
                ->exists();

            $this->logUnscoped($brandId, 1, $suppressed ? 1 : 0);

            return $suppressed;
        } catch (Throwable $e) {
            throw SuppressionCheckFailed::from($e);
        }
    }

    public function suppressedAmong(iterable $emails, ?int $brandId = null): array
    {
        $normalized = [];
        $unusable = [];

        foreach ($emails as $email) {
            $value = is_string($email) ? EmailNormalizer::normalize($email) : null;

            if ($value !== null) {
                $normalized[$value] = true;
            } else {
                // Same answer as isSuppressed(): nothing to look up, so no
                // permission. Keyed by what was passed, as far as it is a string.
                $unusable[is_string($email) ? trim($email) : '(invalid:'.get_debug_type($email).')'] = true;
            }
        }

        $normalized = array_keys($normalized);

        if ($normalized === []) {
            return $unusable;
        }

        try {
            // One query per batch, not one per recipient. The chunked audience
            // loop this feeds used to run a lookup per subscriber.
            $hits = $this->scoped($brandId)
                ->active()
                ->whereIn('email_normalized', $normalized)
                ->pluck('email_normalized')
                ->all();
        } catch (Throwable $e) {
            throw SuppressionCheckFailed::from($e);
        }

        $this->logUnscoped($brandId, count($normalized), count($hits));

        return array_fill_keys($hits, true) + $unusable;
    }

    /**
     * Without a brand id and without a current brand (multi-brand), `visibleTo`
     * would quietly fall back to the default brand and miss every other brand's
     * rows. Fail closed: look at all brands, so a block anywhere counts.
     */
    /** @return Builder<Suppression> */
    private function scoped(?int $brandId): Builder
    {
        if ($this->lacksBrandContext($brandId)) {
            return Suppression::query();
        }

        return Suppression::query()->visibleTo($brandId);
    }

    private function lacksBrandContext(?int $brandId): bool
    {
        if ($brandId !== null) {
            return false;
        }

        $context = app('brand-context');

        return $context->multiBrandEnabled() && ! $context->hasCurrent();
    }

    private function logUnscoped(?int $brandId, int $checked, int $suppressed): void
    {
        if (! $this->lacksBrandContext($brandId)) {
            return;
        }

        Log::warning('statamic-suppression: gate checked without brand context, all brands were consulted.', [
            'checked' => $checked,
            'suppressed' => $suppressed,
        ]);
    }
}
