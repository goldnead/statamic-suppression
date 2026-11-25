<?php

use Goldnead\Suppression\Exceptions\MissingBrandContext;
use Goldnead\Suppression\Models\Suppression;
use Goldnead\Suppression\Reasons;
use Goldnead\Suppression\SuppressionService;

/**
 * Nachfunde zu S2: gleiche Fehlerklasse (stiller Rückfall auf die Standardmarke),
 * an zwei weiteren Stellen.
 */
it('refuses visibleTo(null) in multi-brand mode when no brand is current', function (): void {
    $this->enableMultiBrand();
    $this->makeBrand('brand-a');

    expect(fn () => Suppression::query()->visibleTo(null)->get())->toThrow(MissingBrandContext::class);
    expect(fn () => Suppression::query()->visibleTo()->get())->toThrow(MissingBrandContext::class);
});

it('resolves visibleTo(null) from the current brand in multi-brand mode', function (): void {
    $this->enableMultiBrand();
    $brand = $this->makeBrand('brand-a');
    app(SuppressionService::class)->suppress('objector@example.test', Reasons::COMPLAINT, ['brand_id' => $brand->id]);

    $count = $this->inBrand('brand-a', fn () => Suppression::query()->visibleTo(null)->count());

    expect($count)->toBe(1);
});

it('keeps visibleTo(null) working in single-brand mode', function (): void {
    app(SuppressionService::class)->suppress('objector@example.test', Reasons::COMPLAINT);

    expect(Suppression::query()->visibleTo(null)->count())->toBe(1);
});

it('refuses find(), historyFor() and release() without a brand in multi-brand mode', function (): void {
    $this->enableMultiBrand();
    $this->makeBrand('brand-a');
    $service = app(SuppressionService::class);

    expect(fn () => $service->find('x@example.test'))->toThrow(MissingBrandContext::class);
    expect(fn () => $service->historyFor('x@example.test'))->toThrow(MissingBrandContext::class);
    expect(fn () => $service->release('x@example.test'))->toThrow(MissingBrandContext::class);
});

it('suppression:release reports a missing brand instead of crashing in multi-brand mode', function (): void {
    $this->enableMultiBrand();
    $this->makeBrand('brand-a');

    $this->artisan('suppression:release', ['--email' => 'x@example.test'])
        ->expectsOutputToContain('Pass --brand')
        ->assertFailed();
});

it('returns the existing row for a redelivered provider event even without a brand', function (): void {
    $this->enableMultiBrand();
    $brand = $this->makeBrand('brand-a');
    $service = app(SuppressionService::class);
    $event = [
        'provider' => 'brevo',
        'provider_event_id' => 'evt-1',
        'provider_message_id' => 'msg-1',
    ];

    $first = $service->suppress('objector@example.test', Reasons::COMPLAINT, $event + ['brand_id' => $brand->id]);

    $again = $service->suppress('objector@example.test', Reasons::COMPLAINT, $event);

    expect($again)->not->toBeNull()
        ->and($again->id)->toBe($first->id)
        ->and(Suppression::query()->count())->toBe(1);
});

it('still refuses a first-time brand-scoped event without a brand', function (): void {
    $this->enableMultiBrand();
    $this->makeBrand('brand-a');

    expect(fn () => app(SuppressionService::class)->suppress('objector@example.test', Reasons::COMPLAINT, [
        'provider' => 'brevo',
        'provider_event_id' => 'evt-2',
        'provider_message_id' => 'msg-2',
    ]))->toThrow(MissingBrandContext::class);
});
