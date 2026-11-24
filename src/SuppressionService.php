<?php

namespace Goldnead\Suppression;

use Goldnead\Suppression\Exceptions\ComplaintReleaseRefused;
use Goldnead\Suppression\Exceptions\IncompleteAuditTrail;
use Goldnead\Suppression\Exceptions\MissingBrandContext;
use Goldnead\Suppression\Models\Suppression;
use Goldnead\Suppression\Models\SuppressionEvent;
use Goldnead\Suppression\Support\EmailNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Everything that writes to the suppression tables.
 *
 * Reading is the gate's job and is deliberately a separate object: a send path
 * should be able to depend on the question without being handed the ability to
 * answer it differently.
 */
class SuppressionService
{
    /**
     * Block an address.
     *
     * The scope comes from the reason (D1), never from the caller: a hard bounce
     * is global whoever reports it, and a complaint belongs to the brand that
     * received it. Passing `brand_id` explicitly is possible for the brand-scoped
     * reasons and ignored for the global ones, which is the asymmetry the whole
     * design rests on.
     *
     * Re-suppressing an address that was released does not edit history — it
     * revives the row and writes a fresh `suppressed` event beside the old
     * `released` one.
     *
     * @param  array{
     *     source?: string|null,
     *     brand_id?: int|null,
     *     provider?: string|null,
     *     provider_event_id?: string|null,
     *     provider_message_id?: string|null,
     *     message_uuid?: string|null,
     *     expires_at?: \DateTimeInterface|null,
     *     actor?: string|null,
     *     notes?: string|null,
     *     meta?: array|null,
     *     payload?: array|null,
     *     event_type?: string,
     * }  $attributes
     */
    public function suppress(string $email, string $reason, array $attributes = []): ?Suppression
    {
        Reasons::assertKnown($reason);

        $normalized = EmailNormalizer::normalize($email);

        if ($normalized === null) {
            throw new InvalidArgumentException('Cannot suppress an empty address.');
        }

        $brandId = $this->brandIdFor($reason, $attributes['brand_id'] ?? null);

        $eventType = $attributes['event_type'] ?? SuppressionEvent::TYPE_SUPPRESSED;

        $dedupeKey = SuppressionEvent::dedupeKeyFor(
            $attributes['provider'] ?? null,
            $attributes['provider_event_id'] ?? null,
            $eventType,
            $attributes['provider_message_id'] ?? null,
            $normalized,
        );

        // Redelivery of the same provider event must change nothing at all —
        // not the row, not the count of events. Checked before the transaction
        // so a duplicate costs one indexed read.
        if ($dedupeKey !== null && SuppressionEvent::query()->where('dedupe_key', $dedupeKey)->exists()) {
            return $this->find($normalized, $brandId);
        }

        return DB::transaction(function () use ($normalized, $reason, $brandId, $attributes, $eventType, $dedupeKey) {
            $suppression = Suppression::query()->firstOrNew([
                'brand_id' => $brandId,
                'email_normalized' => $normalized,
            ]);

            $suppression->fill([
                'reason' => $reason,
                'source' => $attributes['source'] ?? $suppression->source,
                'provider_message_id' => $attributes['provider_message_id'] ?? $suppression->provider_message_id,
                'message_uuid' => $attributes['message_uuid'] ?? $suppression->message_uuid,
                'suppressed_at' => now(),
                'expires_at' => $attributes['expires_at'] ?? null,
                'notes' => $attributes['notes'] ?? $suppression->notes,
                'meta' => $attributes['meta'] ?? $suppression->meta,
                // A re-suppression clears the release, but the `released` event
                // stays in the log — that is where the history lives.
                'released_at' => null,
                'released_by' => null,
                'release_reason' => null,
            ]);

            $suppression->save();

            $this->writeEvent($eventType, $normalized, $brandId, [
                'reason' => $reason,
                'source' => $attributes['source'] ?? null,
                'provider' => $attributes['provider'] ?? null,
                'provider_event_id' => $attributes['provider_event_id'] ?? null,
                'provider_message_id' => $attributes['provider_message_id'] ?? null,
                'message_uuid' => $attributes['message_uuid'] ?? null,
                'actor' => $attributes['actor'] ?? null,
                'dedupe_key' => $dedupeKey,
                'payload' => $attributes['payload'] ?? null,
            ]);

            return $suppression;
        });
    }

    /**
     * The ordinary release. Refuses complaints on purpose.
     *
     * Nothing here is a UI preference: this is the method a "release" button on
     * a filtered list calls, and that button must not be able to lift the one
     * block with legal weight. `releaseComplaint()` is the other door.
     */
    public function release(string $email, array $context = [], ?int $brandId = null): Suppression
    {
        $normalized = $this->requireNormalized($email);
        $suppression = $this->requireSuppression($normalized, $brandId);

        if ($suppression->reason === Reasons::COMPLAINT) {
            throw ComplaintReleaseRefused::for($normalized);
        }

        return $this->writeRelease($suppression, $context);
    }

    /**
     * The deliberate release of a complaint suppression.
     *
     * Mailing an address again after it issued a spam complaint is the one
     * failure mode here with regulatory exposure, so this path does not make the
     * act impossible — it makes it impossible *by accident*, and provable
     * afterwards. Three things are required and none of them is optional:
     *
     *   who   `actor`, the authenticated identifier, from the session or the
     *         console — never from a form field, because a form field is the
     *         attacker's to fill in.
     *   when  the server clock, on both the row and the event.
     *   why   a stated reason that clears the configured minimum length, so
     *         that "ok" does not satisfy the rule.
     *
     * State change and audit event share one transaction. A release that cannot
     * be logged must not happen: that is the whole condition, not a detail of
     * it, and `tests/Feature/ComplaintReleaseTest.php` proves the rollback by
     * making the event insert fail.
     */
    public function releaseComplaint(string $email, string $actor, string $reason, ?int $brandId = null, array $context = []): Suppression
    {
        $normalized = $this->requireNormalized($email);
        $suppression = $this->requireSuppression($normalized, $brandId);

        $actor = trim($actor);
        $reason = trim($reason);

        if ($actor === '') {
            throw IncompleteAuditTrail::missingActor($normalized);
        }

        $minimum = (int) config('suppression.release.min_reason_length', 20);

        if (mb_strlen($reason) < $minimum) {
            throw IncompleteAuditTrail::reasonTooShort($normalized, mb_strlen($reason), $minimum);
        }

        return $this->writeRelease($suppression, array_merge($context, [
            'actor' => $actor,
            'reason' => $reason,
        ]));
    }

    /**
     * Record one soft bounce and promote the address if the window says so.
     *
     * An individual soft bounce is never a suppression. A full mailbox or a
     * momentarily unreachable server is a fact about today, and killing a valid
     * subscriber over it is the defect this counting exists to avoid.
     *
     * @return Suppression|null the suppression, if this bounce tripped the threshold
     */
    public function recordSoftBounce(string $email, array $attributes = []): ?Suppression
    {
        $normalized = $this->requireNormalized($email);

        // Soft bounces count toward a global promotion, so they are logged in
        // the same scope the promotion will land in. Counting them per brand
        // and promoting globally would let two brands each stay one bounce
        // below a threshold that the mailbox passed twice over.
        $brandId = $this->brandIdFor(Reasons::SOFT_BOUNCE_THRESHOLD, null);

        $dedupeKey = SuppressionEvent::dedupeKeyFor(
            $attributes['provider'] ?? null,
            $attributes['provider_event_id'] ?? null,
            SuppressionEvent::TYPE_SOFT_BOUNCE,
            $attributes['provider_message_id'] ?? null,
            $normalized,
        );

        if ($dedupeKey !== null && SuppressionEvent::query()->where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        $this->writeEvent(SuppressionEvent::TYPE_SOFT_BOUNCE, $normalized, $brandId, [
            'reason' => null,
            'source' => $attributes['source'] ?? null,
            'provider' => $attributes['provider'] ?? null,
            'provider_event_id' => $attributes['provider_event_id'] ?? null,
            'provider_message_id' => $attributes['provider_message_id'] ?? null,
            'message_uuid' => $attributes['message_uuid'] ?? null,
            'dedupe_key' => $dedupeKey,
            'payload' => $attributes['payload'] ?? null,
        ]);

        if ($this->softBounceCount($normalized, $brandId) < $this->softBounceThreshold()) {
            return null;
        }

        return $this->suppress($normalized, Reasons::SOFT_BOUNCE_THRESHOLD, [
            'source' => $attributes['source'] ?? null,
            'meta' => ['threshold' => $this->softBounceThreshold(), 'window_days' => $this->softBounceWindowDays()],
        ]);
    }

    /**
     * A successful delivery. Resets the soft-bounce window.
     *
     * Without this a long-lived address slowly accumulates unrelated transient
     * failures over months until it trips a threshold meant to describe a
     * mailbox that is currently failing.
     */
    public function recordDelivery(string $email, array $attributes = []): void
    {
        $normalized = $this->requireNormalized($email);
        $brandId = $this->brandIdFor(Reasons::SOFT_BOUNCE_THRESHOLD, null);

        $this->writeEvent(SuppressionEvent::TYPE_REASSERTED, $normalized, $brandId, [
            'source' => $attributes['source'] ?? null,
            'provider' => $attributes['provider'] ?? null,
            'provider_message_id' => $attributes['provider_message_id'] ?? null,
            'message_uuid' => $attributes['message_uuid'] ?? null,
        ]);
    }

    /** The scope a reason writes into: 0 for global, the brand id otherwise. */
    public function brandIdFor(string $reason, ?int $brandId = null): int
    {
        if (Reasons::isGlobal($reason)) {
            return Reasons::GLOBAL_BRAND_ID;
        }

        if ($brandId !== null) {
            return $brandId;
        }

        // `hasCurrent()` is always true in single-brand mode, so only a
        // multi-brand installation with nothing resolved gets here.
        if (! app('brand-context')->hasCurrent()) {
            throw MissingBrandContext::forReason($reason);
        }

        return (int) app('brand-context')->currentId();
    }

    /**
     * The first row for an address in the scopes visible to the brand.
     *
     * By default any row, released or expired included: that is what a history
     * view wants. Pass `activeOnly` to ask what blocks right now — an old
     * released global row would otherwise sort ahead of (and hide) an active
     * brand row, because the lookup orders by `brand_id`.
     */
    public function find(string $email, ?int $brandId = null, bool $activeOnly = false): ?Suppression
    {
        $normalized = EmailNormalizer::normalize($email);

        if ($normalized === null) {
            return null;
        }

        return Suppression::query()
            ->visibleTo($brandId)
            ->forEmail($normalized)
            ->when($activeOnly, fn ($query) => $query->active())
            ->orderBy('brand_id')
            ->first();
    }

    /** Every row for an address, in every scope. The CP's detail view. */
    public function historyFor(string $email, ?int $brandId = null)
    {
        $normalized = EmailNormalizer::normalize($email) ?? '';

        return SuppressionEvent::query()
            ->visibleTo($brandId)
            ->where('email_normalized', $normalized)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();
    }

    public function softBounceThreshold(): int
    {
        return (int) config('suppression.soft_bounce.threshold', 5);
    }

    public function softBounceWindowDays(): int
    {
        return (int) config('suppression.soft_bounce.window_days', 30);
    }

    /**
     * Soft bounces inside the window, since the last successful delivery.
     *
     * The delivery boundary is drawn on the primary key rather than on
     * `occurred_at`, and that is not a detail: a delivery and a bounce recorded
     * in the same second are indistinguishable by timestamp, so a timestamp
     * comparison would sometimes count a bounce the delivery already answered
     * for and sometimes not. Insertion order is the only thing that says which
     * came first, and a threshold that is occasionally off by one is a
     * threshold that occasionally kills a valid subscriber.
     */
    public function softBounceCount(string $emailNormalized, int $brandId): int
    {
        $query = SuppressionEvent::query()
            ->where('email_normalized', $emailNormalized)
            ->where('brand_id', $brandId);

        $lastDeliveryId = (clone $query)
            ->where('event_type', SuppressionEvent::TYPE_REASSERTED)
            ->where('occurred_at', '>=', now()->subDays($this->softBounceWindowDays()))
            ->max('id');

        $counting = (clone $query)
            ->where('event_type', SuppressionEvent::TYPE_SOFT_BOUNCE)
            ->where('occurred_at', '>=', now()->subDays($this->softBounceWindowDays()));

        if ($lastDeliveryId !== null) {
            $counting->where('id', '>', $lastDeliveryId);
        }

        return $counting->count();
    }

    protected function writeRelease(Suppression $suppression, array $context): Suppression
    {
        $actor = isset($context['actor']) ? trim((string) $context['actor']) : null;
        $reason = isset($context['reason']) ? trim((string) $context['reason']) : null;

        return DB::transaction(function () use ($suppression, $actor, $reason) {
            $suppression->forceFill([
                'released_at' => now(),
                'released_by' => $actor,
                'release_reason' => $reason,
            ])->save();

            $this->writeEvent(
                SuppressionEvent::TYPE_RELEASED,
                $suppression->email_normalized,
                (int) $suppression->brand_id,
                [
                    'reason' => $suppression->reason,
                    'actor' => $actor,
                    // Manual events carry no dedupe key on purpose: a second,
                    // legitimate release of the same address must not be
                    // swallowed as a duplicate of the first.
                    'dedupe_key' => null,
                    'payload' => array_filter([
                        'release_reason' => $reason,
                        'released_by' => $actor,
                    ], fn ($value) => $value !== null),
                ]
            );

            return $suppression->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function writeEvent(string $type, string $emailNormalized, int $brandId, array $attributes = []): SuppressionEvent
    {
        try {
            return SuppressionEvent::query()->create(array_merge([
                'brand_id' => $brandId,
                'email_normalized' => $emailNormalized,
                'event_type' => $type,
                'occurred_at' => now(),
            ], $attributes));
        } catch (QueryException $e) {
            // A losing race on the dedupe unique is not an error: the other
            // writer recorded the same provider event. Anything else is.
            if ($this->isDuplicateKey($e) && ($attributes['dedupe_key'] ?? null) !== null) {
                return SuppressionEvent::query()->where('dedupe_key', $attributes['dedupe_key'])->firstOrFail();
            }

            throw $e;
        }
    }

    protected function isDuplicateKey(QueryException $e): bool
    {
        return str_contains(strtolower($e->getMessage()), 'unique')
            || in_array($e->getCode(), ['23000', '23505'], true);
    }

    protected function requireNormalized(string $email): string
    {
        $normalized = EmailNormalizer::normalize($email);

        if ($normalized === null) {
            throw new InvalidArgumentException('Cannot act on an empty address.');
        }

        return $normalized;
    }

    protected function requireSuppression(string $normalized, ?int $brandId): Suppression
    {
        $suppression = Suppression::query()
            ->visibleTo($brandId)
            ->forEmail($normalized)
            ->whereNull('released_at')
            ->orderBy('brand_id')
            ->first();

        if (! $suppression) {
            throw new InvalidArgumentException("No active suppression for [{$normalized}].");
        }

        return $suppression;
    }
}
