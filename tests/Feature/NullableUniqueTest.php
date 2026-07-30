<?php

use Goldnead\Suppression\Models\SuppressionEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The two uniques in this package pull in opposite directions, and both of them
 * are about NULL.
 *
 * A unique index does not bind NULL: MySQL treats NULLs as distinct, so any
 * number of rows may carry none. That is a trap in one place and the whole
 * mechanism in the other, and an implementation that gets either backwards
 * looks perfectly healthy until it is in production.
 *
 * Every assertion here is behavioural. An index by name can exist over the
 * wrong columns, over a nullable column, or not bite at all — the only thing
 * that settles it is writing the row and seeing what the database does.
 */
it('refuses a second row for the same address in the same scope', function (): void {
    // brand_id is NOT NULL with a default of 0 for exactly this reason. Had
    // "global" been expressed as NULL, both of these would be accepted and the
    // table would grow duplicates of the one fact it exists to state once.
    $row = [
        'uuid' => (string) Str::uuid(),
        'brand_id' => 0,
        'email_normalized' => 'dead@example.test',
        'reason' => 'hard_bounce',
        'suppressed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('suppressions')->insert($row);

    expect(fn () => DB::table('suppressions')->insert([...$row, 'uuid' => (string) Str::uuid()]))
        ->toThrow(QueryException::class);

    expect(DB::table('suppressions')->where('email_normalized', 'dead@example.test')->count())->toBe(1);
});

it('still allows the same address in a different scope', function (): void {
    $base = [
        'email_normalized' => 'both@example.test',
        'reason' => 'manual',
        'suppressed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('suppressions')->insert([...$base, 'uuid' => (string) Str::uuid(), 'brand_id' => 0]);
    DB::table('suppressions')->insert([...$base, 'uuid' => (string) Str::uuid(), 'brand_id' => 7]);

    expect(DB::table('suppressions')->where('email_normalized', 'both@example.test')->count())->toBe(2);
});

it('accepts any number of manual audit events, because they carry no dedupe key', function (): void {
    // Two legitimate releases of the same address, months apart. Neither is a
    // redelivery, so neither may be swallowed as one — which is what a NOT NULL
    // dedupe key, or a key derived from the address alone, would do.
    foreach (range(1, 3) as $i) {
        SuppressionEvent::query()->create([
            'brand_id' => 1,
            'email_normalized' => 'released@example.test',
            'event_type' => SuppressionEvent::TYPE_RELEASED,
            'actor' => 'user:'.$i,
            'dedupe_key' => null,
        ]);
    }

    expect(SuppressionEvent::query()->where('email_normalized', 'released@example.test')->count())->toBe(3);
});

it('refuses a second event carrying the same dedupe key', function (): void {
    $key = sha1('resend:evt_1:suppressed');

    SuppressionEvent::query()->create([
        'brand_id' => 0,
        'email_normalized' => 'bounced@example.test',
        'event_type' => SuppressionEvent::TYPE_SUPPRESSED,
        'dedupe_key' => $key,
    ]);

    expect(fn () => SuppressionEvent::query()->create([
        'brand_id' => 0,
        'email_normalized' => 'bounced@example.test',
        'event_type' => SuppressionEvent::TYPE_SUPPRESSED,
        'dedupe_key' => $key,
    ]))->toThrow(QueryException::class);
});

it('derives no dedupe key when the provider offers nothing to key on', function (): void {
    // A manual action has no provider, so there is nothing to deduplicate
    // against and the key is absent rather than invented.
    expect(SuppressionEvent::dedupeKeyFor(null, null, 'released'))->toBeNull()
        ->and(SuppressionEvent::dedupeKeyFor('brevo', null, 'suppressed'))->toBeNull()
        ->and(SuppressionEvent::dedupeKeyFor('brevo', null, 'suppressed', 'msg-1', 'a@b.test'))->not->toBeNull()
        ->and(SuppressionEvent::dedupeKeyFor('resend', 'evt_1', 'suppressed'))
        ->toBe(sha1('resend:evt_1:suppressed'));
});
