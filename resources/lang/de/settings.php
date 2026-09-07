<?php

/*
 * Die Beschriftungen der Einstellungsseite.
 *
 * Feldschlüssel sind der Config-Pfad mit flachgelegten Punkten
 * (`soft_bounce.threshold` → `soft_bounce_threshold`).
 */

return [

    'permission_group' => 'Sperrliste',
    'permission_manage' => 'Einstellungen der Sperrliste verwalten',

    'scopes' => [
        'global' => 'Alle Marken',
        'brand' => 'Nur die eintragende Marke',
    ],

    'groups' => [

        'soft_bounce' => [
            'title' => 'Weiche Bounces',
            'description' => 'Wann ein Lauf weicher Bounces eine Adresse sperrt. Die beiden Werte stammen aus einer Zeit vor den ersten echten Daten und stehen genau deshalb hier: sie sollen nachjustiert werden. Eine Umgebungsvariable setzt den Ausgangswert, ein hier gespeicherter Wert schlägt sie, und zurück auf Standard führt wieder auf die Variable.',
        ],

        'release' => [
            'title' => 'Freigabe',
            'description' => 'Was jemand angeben muss, der eine Sperre bewusst aufhebt.',
        ],

        'scopes' => [
            'title' => 'Reichweite je Grund',
            'description' => 'Ob ein Grund die Adresse überall sperrt oder nur bei der Marke, die ihn eingetragen hat. Umkehrbar ohne Migration: die Marke steht auf jeder Zeile, 0 heißt alle. Eine Änderung wirkt auf neue Einträge; bereits geschriebene Zeilen behalten die Reichweite, mit der sie entstanden sind.',
        ],

        'insights' => [
            'title' => 'Insights',
            'description' => 'Ob die beiden Kennzahlen dem Analyse-Addon angeboten werden.',
        ],

    ],

    'fields' => [

        'soft_bounce_threshold' => [
            'label' => 'Bounces bis zur Sperre',
            'description' => 'So viele weiche Bounces innerhalb des Fensters sperren eine Adresse. Ein niedrigerer Wert sperrt früher und trifft dabei mehr Postfächer, die nur voll waren. Gilt für Bounces, die ab jetzt gezählt werden.',
        ],

        'soft_bounce_window_days' => [
            'label' => 'Fenster in Tagen',
            'description' => 'So weit zurück wird beim Zählen geschaut. Ein längeres Fenster sammelt mehr Bounces und sperrt damit früher, auch wenn der Schwellenwert gleich bleibt.',
        ],

        'release_min_reason_length' => [
            'label' => 'Mindestlänge der Begründung',
            'description' => 'Kürzere Begründungen werden beim Freigeben abgelehnt. Der Punkt des Feldes ist, dass es ein Jahr später noch etwas bedeutet; ein Wort ist ein Häkchen im Gewand eines Textfeldes. 0 heißt: keine Mindestlänge.',
        ],

        'scopes_hard_bounce' => [
            'label' => 'Harter Bounce',
            'description' => 'Eine Eigenschaft des Postfachs: es bounct bei jeder Marke gleich, und jede Marke, die das erneut lernt, zahlt dafür mit einem Ruf, den alle teilen.',
        ],

        'scopes_invalid_email' => [
            'label' => 'Ungültige Adresse',
            'description' => 'Wie der harte Bounce eine Eigenschaft der Adresse selbst, nicht der Beziehung.',
        ],

        'scopes_soft_bounce_threshold' => [
            'label' => 'Schwelle weicher Bounces erreicht',
            'description' => 'Ob eine erreichte Schwelle nur bei der zählenden Marke oder überall sperrt.',
        ],

        'scopes_complaint' => [
            'label' => 'Beschwerde',
            'description' => 'Eine Eigenschaft der Beziehung: der Empfänger hat diesem Absender widersprochen, was über andere nichts aussagt. Auf alle Marken zu stellen widerspricht der Regel, dass Einwilligung nicht zwischen Marken überläuft.',
        ],

        'scopes_manual' => [
            'label' => 'Von Hand gesperrt',
            'description' => 'Ob eine von Hand eingetragene Sperre für alle Marken gilt.',
        ],

        'scopes_provider_import' => [
            'label' => 'Übernahme vom Versanddienst',
            'description' => 'Adressen, die von der Sperrliste des Versanddienstes gespiegelt wurden.',
        ],

        'insights_enabled' => [
            'label' => 'Kennzahlen anbieten',
            'description' => 'Aus heißt: die beiden Kennzahlen erscheinen im Insights-Dashboard gar nicht. Das ist etwas anderes als eine Null.',
        ],

    ],

];
