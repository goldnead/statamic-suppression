<?php

use Goldnead\Suppression\Models\Suppression;
use Goldnead\Suppression\Reasons;
use Goldnead\Suppression\SuppressionService;

/**
 * S6: ein altes, freigegebenes globales Release darf ein aktives Complaint der
 * Marke nicht verdecken. `find()` lieferte die erste Zeile nach `brand_id`,
 * die freigegebene Zeile mit brand_id 0, und der Befehl las daran die falsche
 * Art.
 */
beforeEach(function (): void {
    $this->enableMultiBrand();
    $this->brandId = $this->makeBrand('brand-a')->id;
    $service = app(SuppressionService::class);

    $service->suppress('both@example.test', Reasons::HARD_BOUNCE);
    $service->release('both@example.test', ['actor' => 'user:1', 'reason' => 'Mailbox is back, checked.'], $this->brandId);
    $service->suppress('both@example.test', Reasons::COMPLAINT, ['brand_id' => $this->brandId]);
});

it('releases an active brand complaint even when an old global row was released earlier', function (): void {
    $this->artisan('suppression:release', [
        '--email' => 'both@example.test',
        '--brand' => $this->brandId,
        '--force' => true,
        '--actor' => 'user:7',
        '--reason' => 'Written re-consent on file, ticket 1183, checked by Adrian.',
    ])->assertSuccessful();

    expect(Suppression::query()->where('brand_id', $this->brandId)->sole()->released_at)->not->toBeNull();
});

it('refuses to release a brand complaint without --force even when an old global row was released earlier', function (): void {
    $this->artisan('suppression:release', ['--email' => 'both@example.test', '--brand' => $this->brandId])
        ->assertFailed();

    expect(Suppression::query()->where('brand_id', $this->brandId)->sole()->released_at)->toBeNull();
});

it('find() can be limited to rows that block right now', function (): void {
    $service = app(SuppressionService::class);

    expect($service->find('both@example.test', $this->brandId)->brand_id)->toBe(Reasons::GLOBAL_BRAND_ID)
        ->and($service->find('both@example.test', $this->brandId, activeOnly: true)->brand_id)->toBe($this->brandId);
});

it('does not release an expired global row in place of the active complaint', function (): void {
    $service = app(SuppressionService::class);
    $service->suppress('lapsed@example.test', Reasons::HARD_BOUNCE, ['expires_at' => now()->subDay()]);
    $service->suppress('lapsed@example.test', Reasons::COMPLAINT, ['brand_id' => $this->brandId]);

    $this->artisan('suppression:release', [
        '--email' => 'lapsed@example.test',
        '--brand' => $this->brandId,
        '--force' => true,
        '--actor' => 'user:7',
        '--reason' => 'Written re-consent on file, ticket 1183, checked by Adrian.',
    ])->assertSuccessful();

    $complaint = Suppression::query()->where('email_normalized', 'lapsed@example.test')->where('brand_id', $this->brandId)->sole();
    $expired = Suppression::query()->where('email_normalized', 'lapsed@example.test')->where('brand_id', Reasons::GLOBAL_BRAND_ID)->sole();

    expect($complaint->released_at)->not->toBeNull()
        ->and($expired->released_at)->toBeNull();
});
