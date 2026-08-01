<?php

namespace Goldnead\Suppression\Models;

use Goldnead\Suppression\Reasons;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One current fact about one address, in one scope.
 *
 * **This model deliberately does not use `HasBrand`.** It is the first table in
 * the family to opt out, and the reason is safety-critical rather than stylistic:
 * `BrandScope::apply()` adds `where brand_id = currentId()`, which would hide
 * every global row (`brand_id = 0`) from the gate query — a fail-open bug in
 * precisely the query that must never fail open. The trait's `creating` hook
 * would additionally stamp the current brand over the intended 0, so a hard
 * bounce recorded in brand A would silently become brand A's private business.
 *
 * `scopeVisibleTo()` replaces it: global rows plus one brand's own. Writes set
 * `brand_id` explicitly from the reason's scope rule, never implicitly.
 *
 * A future agent "fixing" this model to use `HasBrand` for consistency would
 * reintroduce both bugs. `tests/Feature/CrossBrandTest.php` is what stops that.
 *
 * The `@property` block below is the schema of `suppressions`, restated for
 * static analysis. It is part of the public surface: the facade hands these
 * instances to consumers, so a column rename here is a breaking change.
 *
 * @property int $id
 * @property string $uuid
 * @property int $brand_id 0 means every brand
 * @property string $email_normalized
 * @property string $reason one of Reasons::all()
 * @property string|null $source
 * @property string|null $provider_message_id
 * @property string|null $message_uuid
 * @property Carbon $suppressed_at
 * @property Carbon|null $expires_at null means permanent
 * @property Carbon|null $released_at
 * @property string|null $released_by
 * @property string|null $release_reason
 * @property string|null $notes
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Suppression extends Model
{
    protected $table = 'suppressions';

    protected $guarded = [];

    protected $casts = [
        'brand_id' => 'integer',
        'meta' => 'array',
        'suppressed_at' => 'datetime',
        'expires_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $suppression): void {
            $suppression->uuid ??= (string) Str::uuid();
            $suppression->suppressed_at ??= now();
        });
    }

    /**
     * Rows a given brand may see: the global ones plus its own.
     *
     * Passing null resolves the current brand. In single-brand mode that is the
     * default brand, which is harmless — global rows are visible either way.
     */
    public function scopeVisibleTo(Builder $query, ?int $brandId = null): Builder
    {
        $brandId ??= app('brand-context')->currentId();

        return $query->whereIn(
            $this->getTable().'.brand_id',
            array_values(array_unique([Reasons::GLOBAL_BRAND_ID, $brandId]))
        );
    }

    /**
     * Rows that actually block a send right now.
     *
     * Two things disqualify a row: it was released, or it expired. Both are
     * ordinary states rather than deletions, so the history stays readable.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull($this->getTable().'.released_at')
            ->where(function (Builder $query): void {
                $query->whereNull($this->getTable().'.expires_at')
                    ->orWhere($this->getTable().'.expires_at', '>', now());
            });
    }

    public function scopeForEmail(Builder $query, string $emailNormalized): Builder
    {
        return $query->where($this->getTable().'.email_normalized', $emailNormalized);
    }

    public function isGlobal(): bool
    {
        return (int) $this->brand_id === Reasons::GLOBAL_BRAND_ID;
    }

    public function isReleased(): bool
    {
        return $this->released_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return ! $this->isReleased() && ! $this->isExpired();
    }

    /** The audit trail for this address in this scope, newest first. */
    public function events(): Builder
    {
        return SuppressionEvent::query()
            ->where('email_normalized', $this->email_normalized)
            ->where('brand_id', $this->brand_id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
    }
}
