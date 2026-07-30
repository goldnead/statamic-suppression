<?php

namespace Goldnead\Suppression;

use InvalidArgumentException;

/**
 * The reasons an address can be blocked, and the one thing that separates them:
 * whether the fact belongs to the mailbox or to a relationship.
 *
 * A hard bounce is a property of the **mailbox**. The address does not exist; it
 * will bounce identically from every brand, and each retry from a second brand
 * damages a sending reputation both of them share. Re-learning that per brand
 * costs real deliverability to discover something already known.
 *
 * A complaint is a property of the **relationship**. The recipient objected to
 * *this sender's* mail. It says nothing about whether a different brand may
 * write to them, and treating it as global would contradict the consent-bleed
 * decision the subscription schema already carries.
 *
 * That split is decision D1. It lives in config rather than in a migration, so
 * reversing it costs a config change: the schema stores an explicit `brand_id`
 * either way and 0 simply means "every brand".
 */
final class Reasons
{
    /** Provider reports a permanent bounce. The mailbox is gone. */
    public const HARD_BOUNCE = 'hard_bounce';

    /** Provider reports the address is malformed or non-existent. */
    public const INVALID_EMAIL = 'invalid_email';

    /** N soft bounces inside the window promoted the address (see SuppressionService). */
    public const SOFT_BOUNCE_THRESHOLD = 'soft_bounce_threshold';

    /** A spam complaint or feedback-loop hit. The one reason with legal weight. */
    public const COMPLAINT = 'complaint';

    /** An editor blocked the address by hand. */
    public const MANUAL = 'manual';

    /** Mirrored from the ESP's own suppression list. */
    public const PROVIDER_IMPORT = 'provider_import';

    /** Applies to every brand. Stored as `brand_id = 0`. */
    public const SCOPE_GLOBAL = 'global';

    /** Applies to the brand that recorded it. */
    public const SCOPE_BRAND = 'brand';

    /** The `brand_id` value that means "every brand". */
    public const GLOBAL_BRAND_ID = 0;

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::HARD_BOUNCE,
            self::INVALID_EMAIL,
            self::SOFT_BOUNCE_THRESHOLD,
            self::COMPLAINT,
            self::MANUAL,
            self::PROVIDER_IMPORT,
        ];
    }

    /**
     * The D1 default: deliverability facts are global, consent facts are not.
     *
     * @return array<string, string>
     */
    public static function defaultScopes(): array
    {
        return [
            self::HARD_BOUNCE => self::SCOPE_GLOBAL,
            self::INVALID_EMAIL => self::SCOPE_GLOBAL,
            self::SOFT_BOUNCE_THRESHOLD => self::SCOPE_GLOBAL,
            self::PROVIDER_IMPORT => self::SCOPE_GLOBAL,
            self::COMPLAINT => self::SCOPE_BRAND,
            self::MANUAL => self::SCOPE_BRAND,
        ];
    }

    /**
     * Is this reason recorded once for everyone, or once per brand?
     *
     * Read from config so D1 stays reversible without a migration. An unknown
     * reason is brand-scoped: a reason nobody configured must not silently
     * acquire the widest possible blast radius.
     */
    public static function scopeFor(string $reason): string
    {
        $configured = (array) config('suppression.scopes', []);

        $scope = $configured[$reason] ?? self::defaultScopes()[$reason] ?? self::SCOPE_BRAND;

        if (! in_array($scope, [self::SCOPE_GLOBAL, self::SCOPE_BRAND], true)) {
            throw new InvalidArgumentException(
                "Suppression reason [{$reason}] has an unknown scope [{$scope}]; expected 'global' or 'brand'."
            );
        }

        return $scope;
    }

    public static function isGlobal(string $reason): bool
    {
        return self::scopeFor($reason) === self::SCOPE_GLOBAL;
    }

    public static function assertKnown(string $reason): string
    {
        if (! in_array($reason, self::all(), true)) {
            throw new InvalidArgumentException(
                "Unknown suppression reason [{$reason}]. Known: ".implode(', ', self::all()).'.'
            );
        }

        return $reason;
    }
}
