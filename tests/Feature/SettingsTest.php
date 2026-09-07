<?php

use Goldnead\BrandContext\Facades\BrandSettings;
use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\Suppression\Exceptions\IncompleteAuditTrail;
use Goldnead\Suppression\Reasons;
use Goldnead\Suppression\Support\Settings;
use Goldnead\Suppression\SuppressionService;

/**
 * Die Einstellungen dieses Pakets an der geteilten Schicht.
 *
 * Dieses Paket hatte bis jetzt gar keine Oberfläche, und seine Config sagt
 * beim Schwellenwert wörtlich, der Wert solle nachjustiert werden, sobald es
 * etwas zum Nachjustieren gibt. Geprüft wird deshalb nicht nur, dass ein Wert
 * in der Config landet, sondern dass er das Verhalten ändert: dass eine
 * Adresse bei drei weichen Bounces gesperrt wird, wenn drei eingestellt sind.
 */
beforeEach(function (): void {
    $this->service = app(SuppressionService::class);
});

it('registers itself with the shared settings layer', function () {
    $registry = app(SettingsRegistry::class);

    expect($registry->has('suppression'))->toBeTrue('boot() hat die Einstellungen nicht angemeldet.')
        ->and($registry->provider('suppression'))->toBe(Settings::class)
        ->and($registry->configPath('suppression'))->toBe('suppression')
        ->and($registry->permission('suppression'))->toBe('manage suppression settings');
});

it('suppresses at the saved threshold, not the packaged one', function () {
    // Vorgabe ist 5. Zwei weiche Bounces sperren nichts.
    $this->service->recordSoftBounce('tuning@example.test');
    $this->service->recordSoftBounce('tuning@example.test');

    expect($this->service->find('tuning@example.test'))->toBeNull();

    BrandSettings::for('suppression')->save(['soft_bounce.threshold' => 3]);

    expect($this->service->softBounceThreshold())->toBe(3);

    // Der dritte sperrt jetzt. Das ist die Grenze, die zaehlt: nicht ein Wert
    // in der Config, sondern eine Adresse, die vorher durchgekommen waere.
    $suppression = $this->service->recordSoftBounce('tuning@example.test');

    expect($suppression)->not->toBeNull()
        ->and($suppression->reason)->toBe(Reasons::SOFT_BOUNCE_THRESHOLD);
});

it('refuses a release below the saved minimum reason length', function () {
    $this->service->suppress('complainer@example.test', Reasons::COMPLAINT);

    // Vorgabe 20: eine 25 Zeichen lange Begruendung geht durch.
    BrandSettings::for('suppression')->save(['release.min_reason_length' => 40]);

    expect(fn () => $this->service->releaseComplaint(
        'complainer@example.test',
        'adrian',
        'Kunde hat schriftlich widersprochen.',
    ))->toThrow(IncompleteAuditTrail::class);
});

it('moves a reason between global and brand scope', function () {
    // Eine Beschwerde ist ab Werk markengebunden: sie sagt etwas ueber diese
    // Beziehung aus, nicht ueber jede andere.
    expect(Reasons::isGlobal(Reasons::COMPLAINT))->toBeFalse()
        ->and($this->service->brandIdFor(Reasons::COMPLAINT))->not->toBe(Reasons::GLOBAL_BRAND_ID);

    BrandSettings::for('suppression')->save(['scopes.complaint' => Reasons::SCOPE_GLOBAL]);

    expect(Reasons::isGlobal(Reasons::COMPLAINT))->toBeTrue()
        ->and($this->service->brandIdFor(Reasons::COMPLAINT))->toBe(Reasons::GLOBAL_BRAND_ID);
});

it('offers a select for every reason the code knows', function () {
    // Sechs einzelne Auswahlfelder statt eines Abbildungsfeldes: die
    // Schluessel stehen im Code, der Betreiber erfindet hier keine. Ein
    // dritter Wert liesse `scopeFor()` mitten im Versand werfen.
    $fields = app(SettingsRegistry::class)->fields('suppression');

    foreach (Reasons::all() as $reason) {
        expect($fields)->toHaveKey('scopes.'.$reason)
            ->and($fields['scopes.'.$reason]['type'])->toBe('select');
    }

    expect(app(SettingsRegistry::class)->options('suppression', 'scopes.complaint'))
        ->toHaveCount(2);
});
