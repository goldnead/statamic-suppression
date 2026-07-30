<?php

namespace Goldnead\Suppression\Contracts;

use Goldnead\Suppression\Exceptions\SuppressionCheckFailed;

/**
 * The one question every sending path must ask, and the contract for how it is
 * allowed to be answered.
 *
 * **The gate falls closed, not open.** An implementation may answer true, may
 * answer false, or may throw `SuppressionCheckFailed`. What it may never do is
 * answer false because something went wrong: "the query failed" and "nobody is
 * suppressed" are not the same statement, and treating them as the same means a
 * database hiccup silently turns into a send to every complainant on the list.
 * Callers therefore must not catch the exception and carry on — they abort.
 *
 * This is deliberately the opposite of how a *segment* resolver behaves. A
 * segment that cannot be resolved falls open and the campaign goes to the whole
 * list, which is correct, because a segment narrows an audience and never
 * grants consent. Suppression is the other kind of check entirely: it is the
 * only thing standing between a send and an address that has said no. The two
 * rules look inconsistent side by side and they are not. Anyone tempted to
 * harmonise them should read this paragraph first.
 */
interface Gate
{
    /**
     * May this address be mailed in this brand?
     *
     * @param  int|null  $brandId  null resolves the current brand.
     *
     * @throws SuppressionCheckFailed when the answer cannot be established.
     */
    public function isSuppressed(string $email, ?int $brandId = null): bool;

    /**
     * The suppressed subset of a batch, in one query rather than one per
     * address — the form an audience loop needs.
     *
     * @param  iterable<mixed>  $emails
     * @return array<string, true> keyed by normalized email
     *
     * @throws SuppressionCheckFailed when the answer cannot be established.
     */
    public function suppressedAmong(iterable $emails, ?int $brandId = null): array;
}
