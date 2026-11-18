<?php

use Goldnead\Suppression\Contracts\Gate;
use Goldnead\Suppression\Exceptions\SuppressionCheckFailed;
use Goldnead\Suppression\Reasons;
use Goldnead\Suppression\SuppressionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The question every send path asks, and the promise about how it fails.
 */
it('blocks an address that carries an active suppression', function (): void {
    app(SuppressionService::class)->suppress('Blocked@Example.test', Reasons::HARD_BOUNCE);

    expect(app(Gate::class)->isSuppressed('blocked@example.test'))->toBeTrue()
        // The same address as it was typed. Normalisation happens on both
        // sides or the gate misses everyone who capitalised their address.
        ->and(app(Gate::class)->isSuppressed('  BLOCKED@Example.TEST '))->toBeTrue()
        ->and(app(Gate::class)->isSuppressed('someone-else@example.test'))->toBeFalse();
});

it('lets a released address through again, without losing the record', function (): void {
    $service = app(SuppressionService::class);
    $service->suppress('released@example.test', Reasons::MANUAL);

    expect(app(Gate::class)->isSuppressed('released@example.test'))->toBeTrue();

    $service->release('released@example.test', ['actor' => 'user:1', 'reason' => 'Address was mistyped by an editor.']);

    expect(app(Gate::class)->isSuppressed('released@example.test'))->toBeFalse();

    // Released, not deleted. The row that says this happened is still there —
    // the defect in the prior implementation was a delete that left an address
    // blocked with nothing to explain it.
    expect(DB::table('suppressions')->where('email_normalized', 'released@example.test')->count())->toBe(1);
});

it('lets an expired suppression lapse but keeps a permanent one', function (): void {
    $service = app(SuppressionService::class);

    $service->suppress('temporary@example.test', Reasons::MANUAL, ['expires_at' => now()->subMinute()]);
    $service->suppress('permanent@example.test', Reasons::MANUAL);

    expect(app(Gate::class)->isSuppressed('temporary@example.test'))->toBeFalse()
        ->and(app(Gate::class)->isSuppressed('permanent@example.test'))->toBeTrue();
});

it('answers a whole batch in one query', function (): void {
    $service = app(SuppressionService::class);
    $service->suppress('one@example.test', Reasons::HARD_BOUNCE);
    $service->suppress('two@example.test', Reasons::COMPLAINT);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $hits = app(Gate::class)->suppressedAmong([
        'one@example.test', 'Two@example.test', 'three@example.test', 'four@example.test',
    ]);

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($hits)->toHaveKeys(['one@example.test', 'two@example.test'])
        ->and($hits)->not->toHaveKey('three@example.test')
        // One query for the batch, not one per address. The loop this feeds
        // used to run a lookup per subscriber.
        ->and($queries)->toBe(1);
});

/**
 * T4 — the gate fails closed.
 *
 * Prevents: a database hiccup silently turning into "nobody is suppressed", and
 * therefore into a send to every complainant on the list. This is the single
 * defect the whole package exists to prevent, so it is asserted on both methods
 * rather than on the one a caller happens to use.
 */
it('throws rather than answering "nobody is suppressed" when it cannot ask', function (): void {
    app(SuppressionService::class)->suppress('blocked@example.test', Reasons::HARD_BOUNCE);

    // The table is gone. A gate that returns false here has just granted
    // permission it never established.
    Schema::drop('suppressions');

    expect(fn () => app(Gate::class)->isSuppressed('blocked@example.test'))
        ->toThrow(SuppressionCheckFailed::class);

    expect(fn () => app(Gate::class)->suppressedAmong(['blocked@example.test']))
        ->toThrow(SuppressionCheckFailed::class);
});

it('refuses an address it cannot even normalise', function (): void {
    // Nothing to look up, so there is no basis for permission. Refusing is the
    // closed answer; returning false would be the open one.
    expect(app(Gate::class)->isSuppressed('   '))->toBeTrue();
});

it('reports unusable batch entries as suppressed, like the single check does', function (): void {
    $hits = app(Gate::class)->suppressedAmong(['  ', null, 5, 'fine@example.test']);

    // Three entries cannot be mailed. Dropping them silently would read as
    // "not in the result, so sendable".
    expect($hits)->toHaveCount(3)
        ->and($hits)->not->toHaveKey('fine@example.test')
        ->and(array_values(array_unique($hits)))->toBe([true]);
});

it('checks every brand when no brand is current in multi-brand mode', function (): void {
    $this->enableMultiBrand();
    $this->makeBrand('brand-a');
    $this->makeBrand('brand-b');

    $this->inBrand('brand-b', fn () => app(SuppressionService::class)
        ->suppress('b-only@example.test', Reasons::MANUAL, ['brand_id' => app('brand-context')->currentId()]));

    expect(app('brand-context')->hasCurrent())->toBeFalse()
        // No brand context: the row of brand B must not be missed.
        ->and(app(Gate::class)->isSuppressed('b-only@example.test'))->toBeTrue()
        ->and(app(Gate::class)->suppressedAmong(['b-only@example.test']))->toHaveKey('b-only@example.test')
        // A known brand keeps its precise answer.
        ->and(app(Gate::class)->isSuppressed('b-only@example.test', $this->makeBrand('brand-a')->id))->toBeFalse();
});
