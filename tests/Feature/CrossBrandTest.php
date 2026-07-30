<?php

use Goldnead\Suppression\Contracts\Gate;
use Goldnead\Suppression\Models\Suppression;
use Goldnead\Suppression\Reasons;
use Goldnead\Suppression\SuppressionService;

/**
 * D1 in the only form that means anything: not "the brands are separated", but
 * *which* facts cross the boundary and which do not.
 *
 * A test that only asserts isolation would pass on an implementation where
 * nothing is ever global — and that implementation is broken in the expensive
 * direction, because every brand re-learns every dead mailbox at the cost of a
 * sending reputation they share. So both halves are asserted, in the same file,
 * against the same two brands.
 */
beforeEach(function (): void {
    $this->enableMultiBrand();
    $this->brandA = $this->makeBrand('brand-a');
    $this->brandB = $this->makeBrand('brand-b');
});

/**
 * T5 — global scope actually spans brands.
 *
 * Prevents: the fail-open bug that `HasBrand` on these models would create. A
 * brand scope on the suppression table hides every `brand_id = 0` row from the
 * gate, which is precisely the query that must never fail open. This test is
 * what stops a future refactor from "fixing" the models for consistency.
 */
it('makes a hard bounce recorded in one brand bite in the other', function (): void {
    $service = app(SuppressionService::class);

    $this->inBrand('brand-a', fn () => $service->suppress('dead-mailbox@example.test', Reasons::HARD_BOUNCE));

    $row = Suppression::query()->where('email_normalized', 'dead-mailbox@example.test')->sole();

    expect($row->brand_id)->toBe(Reasons::GLOBAL_BRAND_ID)
        // Written from brand A, and brand A's id was never stamped on it.
        ->and($row->brand_id)->not->toBe($this->brandA->id);

    $inA = $this->inBrand('brand-a', fn () => app(Gate::class)->isSuppressed('dead-mailbox@example.test'));
    $inB = $this->inBrand('brand-b', fn () => app(Gate::class)->isSuppressed('dead-mailbox@example.test'));

    expect($inA)->toBeTrue()
        ->and($inB)->toBeTrue();
});

/**
 * T6 — a brand-scoped complaint does not leak.
 *
 * Prevents: over-blocking that would contradict the consent-bleed decision the
 * subscription schema already carries. Somebody who objected to one brand's
 * mail has said nothing about another's.
 */
it('keeps a complaint inside the brand that received it', function (): void {
    $service = app(SuppressionService::class);

    $this->inBrand('brand-a', fn () => $service->suppress('objector@example.test', Reasons::COMPLAINT));

    $row = Suppression::query()->where('email_normalized', 'objector@example.test')->sole();

    expect($row->brand_id)->toBe($this->brandA->id);

    $inA = $this->inBrand('brand-a', fn () => app(Gate::class)->isSuppressed('objector@example.test'));
    $inB = $this->inBrand('brand-b', fn () => app(Gate::class)->isSuppressed('objector@example.test'));

    expect($inA)->toBeTrue()
        ->and($inB)->toBeFalse();
});

it('lets a global bounce and a brand complaint coexist as two rows about one address', function (): void {
    $service = app(SuppressionService::class);

    $this->inBrand('brand-a', fn () => $service->suppress('both@example.test', Reasons::COMPLAINT));
    $this->inBrand('brand-b', fn () => $service->suppress('both@example.test', Reasons::HARD_BOUNCE));

    $rows = Suppression::query()->where('email_normalized', 'both@example.test')->orderBy('brand_id')->get();

    // Two different facts with two different reversibilities. Collapsing them
    // into one row would mean releasing the complaint also releases the bounce.
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->brand_id)->toBe(Reasons::GLOBAL_BRAND_ID)
        ->and($rows[0]->reason)->toBe(Reasons::HARD_BOUNCE)
        ->and($rows[1]->brand_id)->toBe($this->brandA->id)
        ->and($rows[1]->reason)->toBe(Reasons::COMPLAINT);

    // Releasing the complaint in brand A leaves the mailbox fact standing.
    $this->inBrand('brand-a', fn () => $service->releaseComplaint(
        'both@example.test',
        'user:1',
        'Confirmed with the recipient in writing that the complaint was filed in error.',
    ));

    $inA = $this->inBrand('brand-a', fn () => app(Gate::class)->isSuppressed('both@example.test'));

    expect($inA)->toBeTrue('the global hard bounce must survive the release of a brand-scoped complaint');
});

it('follows the configured scope rather than a hard-coded one', function (): void {
    // D1 is reversible via config, not migration. That property is a decided
    // requirement, so it is asserted rather than assumed.
    config()->set('suppression.scopes.complaint', 'global');

    $service = app(SuppressionService::class);

    $this->inBrand('brand-a', fn () => $service->suppress('config-driven@example.test', Reasons::COMPLAINT));

    $inB = $this->inBrand('brand-b', fn () => app(Gate::class)->isSuppressed('config-driven@example.test'));

    expect(Suppression::query()->where('email_normalized', 'config-driven@example.test')->sole()->brand_id)
        ->toBe(Reasons::GLOBAL_BRAND_ID)
        ->and($inB)->toBeTrue();
});

it('does not widen an unconfigured reason to global by accident', function (): void {
    config()->set('suppression.scopes', []);

    $service = app(SuppressionService::class);

    // With no configuration at all the compiled defaults still apply, so a hard
    // bounce stays global and a manual block stays local. What must never
    // happen is an unknown reason quietly acquiring the widest blast radius.
    $this->inBrand('brand-a', fn () => $service->suppress('unmapped@example.test', Reasons::MANUAL));

    expect(Suppression::query()->where('email_normalized', 'unmapped@example.test')->sole()->brand_id)
        ->toBe($this->brandA->id);
});
