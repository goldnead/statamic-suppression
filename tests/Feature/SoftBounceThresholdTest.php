<?php

use Goldnead\Suppression\Contracts\Gate;
use Goldnead\Suppression\Models\Suppression;
use Goldnead\Suppression\Models\SuppressionEvent;
use Goldnead\Suppression\Reasons;
use Goldnead\Suppression\SuppressionService;

beforeEach(function (): void {
    $this->service = app(SuppressionService::class);
    config()->set('suppression.soft_bounce.threshold', 3);
    config()->set('suppression.soft_bounce.window_days', 30);
});

/**
 * T8 — soft bounces only suppress at the threshold.
 *
 * Prevents: a single full mailbox costing a subscriber. A transient failure is
 * a fact about today, and the whole reason individual soft bounces are events
 * rather than suppressions is that one of them proves nothing.
 */
it('does not block below the threshold and does block at it', function (): void {
    for ($i = 1; $i < 3; $i++) {
        expect($this->service->recordSoftBounce('flaky@example.test'))->toBeNull();
        expect(app(Gate::class)->isSuppressed('flaky@example.test'))->toBeFalse();
    }

    $promoted = $this->service->recordSoftBounce('flaky@example.test');

    expect($promoted)->not->toBeNull()
        ->and($promoted->reason)->toBe(Reasons::SOFT_BOUNCE_THRESHOLD)
        // A promoted soft bounce describes the mailbox, so it lands globally.
        ->and($promoted->brand_id)->toBe(Reasons::GLOBAL_BRAND_ID)
        ->and(app(Gate::class)->isSuppressed('flaky@example.test'))->toBeTrue();
});

it('never writes a suppression for the individual bounces', function (): void {
    $this->service->recordSoftBounce('flaky@example.test');
    $this->service->recordSoftBounce('flaky@example.test');

    expect(Suppression::query()->count())->toBe(0)
        ->and(SuppressionEvent::query()->where('event_type', SuppressionEvent::TYPE_SOFT_BOUNCE)->count())->toBe(2);
});

it('forgets bounces that fell out of the window', function (): void {
    // Two old failures, from a mailbox that has since been fine.
    SuppressionEvent::query()->create([
        'brand_id' => 0,
        'email_normalized' => 'old@example.test',
        'event_type' => SuppressionEvent::TYPE_SOFT_BOUNCE,
        'occurred_at' => now()->subDays(60),
    ]);
    SuppressionEvent::query()->create([
        'brand_id' => 0,
        'email_normalized' => 'old@example.test',
        'event_type' => SuppressionEvent::TYPE_SOFT_BOUNCE,
        'occurred_at' => now()->subDays(45),
    ]);

    expect($this->service->recordSoftBounce('old@example.test'))->toBeNull()
        ->and(app(Gate::class)->isSuppressed('old@example.test'))->toBeFalse();
});

/**
 * A successful delivery resets the window. Without it a long-lived address
 * slowly accumulates unrelated transient failures over months until it trips a
 * threshold that is supposed to describe a mailbox failing *now*.
 */
it('resets the count after a successful delivery', function (): void {
    $this->service->recordSoftBounce('recovered@example.test');
    $this->service->recordSoftBounce('recovered@example.test');

    $this->service->recordDelivery('recovered@example.test');

    expect($this->service->recordSoftBounce('recovered@example.test'))->toBeNull()
        ->and($this->service->recordSoftBounce('recovered@example.test'))->toBeNull()
        ->and(app(Gate::class)->isSuppressed('recovered@example.test'))->toBeFalse();

    // The third one after the reset is what trips it.
    expect($this->service->recordSoftBounce('recovered@example.test'))->not->toBeNull();
});

/**
 * T9 — webhook redelivery is idempotent.
 *
 * Prevents: a duplicate-event storm inflating soft-bounce counts into false
 * suppressions, which is how a redelivering provider can silently destroy a
 * list nobody bounced from.
 */
it('counts a redelivered provider event once', function (): void {
    $event = ['provider' => 'resend', 'provider_event_id' => 'evt_abc'];

    $this->service->recordSoftBounce('duplicate@example.test', $event);
    $this->service->recordSoftBounce('duplicate@example.test', $event);
    $this->service->recordSoftBounce('duplicate@example.test', $event);

    expect(SuppressionEvent::query()->where('event_type', SuppressionEvent::TYPE_SOFT_BOUNCE)->count())->toBe(1)
        ->and(app(Gate::class)->isSuppressed('duplicate@example.test'))->toBeFalse();
});

it('records a redelivered suppression once, with one event', function (): void {
    $event = ['provider' => 'resend', 'provider_event_id' => 'evt_bounce_1', 'source' => 'resend'];

    $this->service->suppress('bounced@example.test', Reasons::HARD_BOUNCE, $event);
    $this->service->suppress('bounced@example.test', Reasons::HARD_BOUNCE, $event);

    expect(Suppression::query()->where('email_normalized', 'bounced@example.test')->count())->toBe(1)
        ->and(SuppressionEvent::query()->where('email_normalized', 'bounced@example.test')->count())->toBe(1);
});
