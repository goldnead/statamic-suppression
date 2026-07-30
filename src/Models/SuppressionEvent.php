<?php

namespace Goldnead\Suppression\Models;

use Goldnead\Suppression\Reasons;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One line of history. Written, never edited, never deleted.
 *
 * Like `Suppression`, this model does not use `HasBrand` — for the same reason,
 * and additionally because an audit log that hides rows depending on who is
 * looking is not an audit log.
 */
class SuppressionEvent extends Model
{
    public const TYPE_SUPPRESSED = 'suppressed';

    public const TYPE_RELEASED = 'released';

    public const TYPE_SOFT_BOUNCE = 'soft_bounce';

    public const TYPE_REASSERTED = 'reasserted';

    public const TYPE_IMPORTED = 'imported';

    protected $table = 'suppression_events';

    protected $guarded = [];

    protected $casts = [
        'brand_id' => 'integer',
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $event->uuid ??= (string) Str::uuid();
            $event->occurred_at ??= now();
        });

        // The log is append-only. Nothing in this package updates or deletes an
        // event, and a consumer that tries has misunderstood what it is for:
        // the history of "blocked → released by X on D because R → blocked
        // again" only means something if every step of it is still there.
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function scopeVisibleTo(Builder $query, ?int $brandId = null): Builder
    {
        $brandId ??= app('brand-context')->currentId();

        return $query->whereIn(
            $this->getTable().'.brand_id',
            array_values(array_unique([Reasons::GLOBAL_BRAND_ID, $brandId]))
        );
    }

    /**
     * The idempotency key that makes webhook redelivery safe.
     *
     * Returns null when the provider gave us nothing to key on. That is not a
     * failure — it means the event must be stored unkeyed, and the nullable
     * unique on the column is what permits it.
     */
    public static function dedupeKeyFor(
        ?string $provider,
        ?string $providerEventId,
        string $eventType,
        ?string $providerMessageId = null,
        ?string $email = null,
    ): ?string {
        if ($provider === null) {
            return null;
        }

        if ($providerEventId !== null && $providerEventId !== '') {
            return sha1($provider.':'.$providerEventId.':'.$eventType);
        }

        if ($providerMessageId !== null && $providerMessageId !== '') {
            return sha1($provider.':'.$providerMessageId.':'.$eventType.':'.(string) $email);
        }

        return null;
    }
}
