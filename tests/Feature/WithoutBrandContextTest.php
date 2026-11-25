<?php

use Goldnead\Suppression\Exceptions\MissingBrandContext;
use Goldnead\Suppression\Models\Suppression;
use Goldnead\Suppression\Reasons;
use Goldnead\Suppression\SuppressionService;

/**
 * S2: ein markengebundener Grund ohne Marke darf nicht in der Standardmarke
 * landen. Im Multi-Brand-Betrieb wird geworfen; ein globaler Grund und der
 * Single-Brand-Betrieb bleiben unberührt.
 */
it('refuses a brand-scoped suppression without any brand in multi-brand mode', function (): void {
    $this->enableMultiBrand();
    $this->makeBrand('brand-a');

    expect(fn () => app(SuppressionService::class)->suppress('objector@example.test', Reasons::COMPLAINT))
        ->toThrow(MissingBrandContext::class);

    expect(Suppression::query()->count())->toBe(0);
});

it('still writes a global reason without a brand in multi-brand mode', function (): void {
    $this->enableMultiBrand();
    $this->makeBrand('brand-a');

    $row = app(SuppressionService::class)->suppress('dead@example.test', Reasons::HARD_BOUNCE);

    expect($row->brand_id)->toBe(Reasons::GLOBAL_BRAND_ID);
});

it('accepts a brand-scoped suppression that names its brand', function (): void {
    $this->enableMultiBrand();
    $brand = $this->makeBrand('brand-a');

    $row = app(SuppressionService::class)->suppress('objector@example.test', Reasons::COMPLAINT, ['brand_id' => $brand->id]);

    expect($row->brand_id)->toBe($brand->id);
});

it('keeps single-brand installations working without a brand', function (): void {
    $row = app(SuppressionService::class)->suppress('objector@example.test', Reasons::COMPLAINT);

    expect($row->brand_id)->toBe(app('brand-context')->defaultId());
});
