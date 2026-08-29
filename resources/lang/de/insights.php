<?php

/**
 * Die Beschriftungen der Kennzahlen, die dieses Addon an `statamic-insights`
 * meldet.
 *
 * Die erste Übersetzungsdatei dieses Pakets: es hat keine Control-Panel-Fläche
 * und brauchte bis hierher keine. Sie steht für sich, weil sie auf dem
 * Bildschirm eines anderen Addons landet und mit der Anbindung wieder
 * verschwindet.
 */
return [

    'metric_group' => 'Sperrliste',

    'metric_events' => 'Sperrlisten-Ereignisse',
    'metric_events_description' => 'Was im Zeitraum passiert ist: Sperrungen, Freigaben, weiche Bounces, Wiedersperrungen, Importe. Gezählt am Zeitpunkt des Anbieters, nicht am Zeitpunkt der Speicherung. Über alle Marken hinweg: ein harter Bounce gilt für jede von ihnen, deshalb zählt diese Zahl die ganze Installation und nicht eine einzelne Marke.',

    'metric_active' => 'Gesperrte Adressen',
    'metric_active_description' => 'Adressen, an die am Ende des Zeitraums nicht gesendet werden durfte: gesperrt, nicht freigegeben, nicht abgelaufen. Ein Stand, kein Ereignis. Über alle Marken hinweg: ein harter Bounce gilt für jede von ihnen, deshalb zählt diese Zahl die ganze Installation und nicht eine einzelne Marke.',

    'metric_breakdown_event_type' => 'Ereignis',
    'metric_breakdown_reason' => 'Grund',
    'metric_breakdown_source' => 'Quelle',

    'metric_no_event_type' => 'Ohne Ereignistyp',
    'metric_no_reason' => 'Ohne Grund',
    'metric_no_source' => 'Ohne Quelle',

    // Die fünf Ereignistypen aus SuppressionEvent.
    'event_suppressed' => 'Gesperrt',
    'event_released' => 'Freigegeben',
    'event_soft_bounce' => 'Weicher Bounce',
    'event_reasserted' => 'Wieder gesperrt',
    'event_imported' => 'Importiert',

    // Die sechs Gründe aus Reasons.
    'reason_hard_bounce' => 'Harter Bounce',
    'reason_invalid_email' => 'Ungültige Adresse',
    'reason_soft_bounce_threshold' => 'Zu viele weiche Bounces',
    'reason_complaint' => 'Beschwerde',
    'reason_manual' => 'Von Hand gesperrt',
    'reason_provider_import' => 'Import vom Anbieter',

];
