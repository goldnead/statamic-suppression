<?php

/*
 * Labels for the settings screen.
 *
 * Field keys are the config path with the dots flattened
 * (`soft_bounce.threshold` → `soft_bounce_threshold`).
 */

return [

    'permission_group' => 'Suppression',
    'permission_manage' => 'Manage suppression settings',

    'scopes' => [
        'global' => 'Every brand',
        'brand' => 'Only the recording brand',
    ],

    'groups' => [

        'soft_bounce' => [
            'title' => 'Soft bounces',
            'description' => 'When a run of soft bounces suppresses an address. Both values come from a time before any real data existed, which is exactly why they are here: they are meant to be tuned. An environment variable sets the starting value, a value saved here outranks it, and back to default follows the variable again.',
        ],

        'release' => [
            'title' => 'Release',
            'description' => 'What somebody deliberately lifting a suppression has to state.',
        ],

        'scopes' => [
            'title' => 'Scope per reason',
            'description' => 'Whether a reason blocks an address everywhere or only in the brand that recorded it. Reversible without a migration: the brand is stored on every row and 0 means every brand. A change applies to new rows; rows already written keep the scope they were written with.',
        ],

        'insights' => [
            'title' => 'Insights',
            'description' => 'Whether the two figures are offered to the analytics addon.',
        ],

    ],

    'fields' => [

        'soft_bounce_threshold' => [
            'label' => 'Bounces before suppression',
            'description' => 'This many soft bounces inside the window suppress an address. A lower value suppresses earlier and catches more mailboxes that were merely full. Applies to bounces counted from now on.',
        ],

        'soft_bounce_window_days' => [
            'label' => 'Window in days',
            'description' => 'How far back the count looks. A longer window collects more bounces and therefore suppresses earlier, even with the threshold unchanged.',
        ],

        'release_min_reason_length' => [
            'label' => 'Minimum length of the stated reason',
            'description' => 'Shorter reasons are refused on release. The point of the field is that it still means something a year later; one word is a checkbox wearing a text input\'s clothes. 0 means no minimum.',
        ],

        'scopes_hard_bounce' => [
            'label' => 'Hard bounce',
            'description' => 'A property of the mailbox: it bounces identically from every brand, and each brand that re-learns it pays with a sending reputation they all share.',
        ],

        'scopes_invalid_email' => [
            'label' => 'Invalid address',
            'description' => 'Like a hard bounce, a property of the address itself rather than of the relationship.',
        ],

        'scopes_soft_bounce_threshold' => [
            'label' => 'Soft bounce threshold reached',
            'description' => 'Whether a reached threshold blocks only in the counting brand or everywhere.',
        ],

        'scopes_complaint' => [
            'label' => 'Complaint',
            'description' => 'A property of the relationship: the recipient objected to this sender, which says nothing about anybody else. Setting it to every brand contradicts the rule that consent does not bleed between brands.',
        ],

        'scopes_manual' => [
            'label' => 'Blocked by hand',
            'description' => 'Whether a suppression entered by hand applies to every brand.',
        ],

        'scopes_provider_import' => [
            'label' => 'Imported from the provider',
            'description' => 'Addresses mirrored from the sending provider\'s own suppression list.',
        ],

        'insights_enabled' => [
            'label' => 'Offer the figures',
            'description' => 'Off means the two figures do not appear on the Insights dashboard at all, which is different from registering a zero.',
        ],

    ],

];
