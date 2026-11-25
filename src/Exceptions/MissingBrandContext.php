<?php

namespace Goldnead\Suppression\Exceptions;

use RuntimeException;

/**
 * A brand-scoped suppression was written with no brand to attach it to, in an
 * installation that runs several.
 *
 * Without this, `currentId()` falls back to the default brand: a complaint
 * received for brand B would be stored as brand A's block, brand A would stop
 * mailing the person, and brand B would keep mailing someone who objected.
 * Single-brand installations never see it, and neither do global reasons.
 */
class MissingBrandContext extends RuntimeException
{
    public static function forReason(string $reason): self
    {
        return new self(
            "A [{$reason}] suppression belongs to one brand, but no brand is current and none was passed. ".
            'Pass `brand_id`, or run inside BrandContext::runFor() / the RunsForEachBrand trait.'
        );
    }
}
