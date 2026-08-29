<?php

/**
 * Labels for the figures this addon offers `statamic-insights`.
 *
 * The first translation file this package has: it owns no Control Panel
 * surface and needed none until now. It stands on its own because it lands on
 * another addon's screen and leaves with the coupling.
 */
return [

    'metric_group' => 'Suppression',

    'metric_events' => 'Suppression events',
    'metric_events_description' => 'What happened in the period: blocks, releases, soft bounces, re-blocks, imports. Counted at the provider\'s moment, not at the moment it was stored. Across every brand: a hard bounce belongs to all of them, so this list is counted for the whole installation rather than per brand.',

    'metric_active' => 'Blocked addresses',
    'metric_active_description' => 'Addresses that could not be mailed at the end of the period: blocked, not released, not expired. A holding, not an event. Across every brand: a hard bounce belongs to all of them, so this list is counted for the whole installation rather than per brand.',

    'metric_breakdown_event_type' => 'Event',
    'metric_breakdown_reason' => 'Reason',
    'metric_breakdown_source' => 'Source',

    'metric_no_event_type' => 'No event type',
    'metric_no_reason' => 'No reason given',
    'metric_no_source' => 'No source',

    // The five event types from SuppressionEvent.
    'event_suppressed' => 'Blocked',
    'event_released' => 'Released',
    'event_soft_bounce' => 'Soft bounce',
    'event_reasserted' => 'Blocked again',
    'event_imported' => 'Imported',

    // The six reasons from Reasons.
    'reason_hard_bounce' => 'Hard bounce',
    'reason_invalid_email' => 'Invalid address',
    'reason_soft_bounce_threshold' => 'Too many soft bounces',
    'reason_complaint' => 'Complaint',
    'reason_manual' => 'Blocked by hand',
    'reason_provider_import' => 'Provider import',

];
