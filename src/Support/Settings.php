<?php

namespace Goldnead\Suppression\Support;

use Goldnead\BrandContext\Contracts\ProvidesSettings;
use Goldnead\Suppression\Reasons;

/**
 * Die Betriebswerte, die ein Betreiber im Control Panel ändern darf.
 *
 * Nur die Feldliste. Seite, Formular, Validierung, Speicher und Rechteprüfung
 * kommen aus `goldnead/statamic-brand-context` — siehe {@see ProvidesSettings}.
 *
 * **Warum dieses Paket überhaupt eine Seite bekommt.** Es hat keine eigene
 * Control-Panel-Oberfläche und soll auch keine. Aber seine Config sagt beim
 * Schwellenwert wörtlich, der Wert stehe dort, weil er nachjustiert werden
 * soll, sobald es etwas zum Nachjustieren gibt — und bis jetzt ging das nur
 * über einen Deploy. Genau dafür ist die geteilte Seite da.
 *
 * **Zu den Umgebungsvariablen.** Vier dieser Werte lesen einen Default aus
 * `env()`. Das bleibt so: die Variable setzt den Ausgangswert, ein hier
 * gespeicherter Wert schlägt ihn, und „zurück auf Standard" führt wieder auf
 * die Variable. Das ist etwas anderes als ein Wert, der der Maschine gehört
 * (ein Speichertreiber, ein Empfänger) — den würde eine Datenbankzeile still
 * überstimmen und beim nächsten Deploy verwirren.
 *
 * **Was nicht hier steht.** Nichts. Dieses Paket hat keinen Schlüssel, der
 * beim Booten gelesen wird: es registriert keine Routen und keine Navigation,
 * und `insights.enabled` wird erst beim Zeichnen einer Kachel gefragt.
 */
class Settings implements ProvidesSettings
{
    /**
     * Bleibt für immer stehen: der Wert steht in `brand_settings.namespace` in
     * jeder Zeile, ein neuer Name verwaist jede gespeicherte Änderung.
     */
    public static function settingsNamespace(): string
    {
        return 'suppression';
    }

    public static function settingsConfigPath(): string
    {
        return 'suppression';
    }

    public static function settingsPermission(): string
    {
        return 'manage suppression settings';
    }

    /**
     * @return array<int, array{title: string, description: string, fields: array<int, array<string, mixed>>}>
     */
    public static function settingsGroups(): array
    {
        return [
            [
                'title' => __('suppression::settings.groups.soft_bounce.title'),
                'description' => __('suppression::settings.groups.soft_bounce.description'),
                'fields' => [
                    static::field('soft_bounce.threshold', 'integer', ['min' => 1]),
                    static::field('soft_bounce.window_days', 'integer', ['min' => 1]),
                ],
            ],
            [
                'title' => __('suppression::settings.groups.release.title'),
                'description' => __('suppression::settings.groups.release.description'),
                'fields' => [
                    // 0 heißt "keine Mindestlänge" und muss erreichbar bleiben.
                    static::field('release.min_reason_length', 'integer', ['min' => 0]),
                ],
            ],
            [
                'title' => __('suppression::settings.groups.scopes.title'),
                'description' => __('suppression::settings.groups.scopes.description'),
                // Sechs einzelne Auswahlfelder statt eines Abbildungsfeldes.
                // Die Schlüssel stehen im Code (Reasons::all()), der Betreiber
                // erfindet hier keine — das ist der Unterschied zu einer
                // Abbildung, deren linke Seite ihm gehört.
                'fields' => array_map(
                    fn (string $reason) => static::field('scopes.'.$reason, 'select', ['options' => static::scopeOptions()]),
                    Reasons::all(),
                ),
            ],
            [
                'title' => __('suppression::settings.groups.insights.title'),
                'description' => __('suppression::settings.groups.insights.description'),
                'fields' => [
                    static::field('insights.enabled', 'boolean'),
                ],
            ],
        ];
    }

    /**
     * Die zwei Reichweiten, die {@see Reasons::scopeFor()} akzeptiert.
     *
     * Als Auswahl, nicht als freier Text: ein dritter Wert lässt
     * `scopeFor()` beim nächsten Sperrversuch eine Ausnahme werfen, also
     * mitten im Versand statt im Formular.
     *
     * @return array<string, string>
     */
    protected static function scopeOptions(): array
    {
        return [
            Reasons::SCOPE_GLOBAL => __('suppression::settings.scopes.global'),
            Reasons::SCOPE_BRAND => __('suppression::settings.scopes.brand'),
        ];
    }

    /**
     * Ein Feld, mit Beschriftung und Beschreibung aus den Sprachdateien.
     *
     * Der Übersetzungsschlüssel ist der Config-Pfad mit flachgelegten Punkten:
     * ein Punkt im Sprachschlüssel ist für den Übersetzer ein Pfadtrenner.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected static function field(string $key, string $type, array $extra = []): array
    {
        $handle = str_replace('.', '_', $key);

        return array_merge([
            'key' => $key,
            'type' => $type,
            'label' => __("suppression::settings.fields.{$handle}.label"),
            'description' => __("suppression::settings.fields.{$handle}.description"),
            'nullable' => false,
        ], $extra);
    }
}
