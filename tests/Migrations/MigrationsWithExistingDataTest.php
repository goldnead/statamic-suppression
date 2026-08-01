<?php

use Goldnead\Suppression\Tests\Fixtures\SuppressionDataFixture;
use Illuminate\Support\Str;

/**
 * The migrations, run against a database that already holds data.
 *
 * A gate whose migration aborts on real data leaves a host with no gate at all,
 * which is strictly worse than never having installed one: the send paths still
 * call it, the table is half-built, and nobody finds out until a complainant is
 * mailed. So the migration path is tested against populated tables from the
 * first release rather than after the first incident.
 *
 * Rows are seeded *between* migrations, not once at the start. A migration that
 * only ever meets rows written by earlier migrations' schema is still only
 * being tested against a fresh install with a bit of data in it. Walking the
 * directory rather than naming files means a migration added later is covered
 * without editing this test.
 *
 * Every assertion about the uniqueness guarantee is behavioural. "The migration
 * ran" and "the constraint is there" are not the same statement, and mistaking
 * one for the other is the defect this apparatus exists for — so nothing here
 * checks an exit code or an index name.
 */
it('runs every migration against tables that already hold rows', function (): void {
    $fixture = new SuppressionDataFixture($this->isolated());
    $batch = 0;

    $this->migrateStepwise($this->currentMigrations(), function () use ($fixture, &$batch): void {
        $fixture->seed($batch++);
    });

    $probe = SuppressionDataFixture::uniqueProbe($batch - 1);

    expect($this->duplicateSuppressionIsAccepted($probe['brand_id'], $probe['email']))
        ->toBeFalse('the (brand, address) unique does not bite after a stepwise migration over populated tables');

    expect($this->isolatedSchema()->hasColumn('suppressions', 'released_at'))->toBeTrue()
        ->and($this->isolatedSchema()->hasColumn('suppression_events', 'dedupe_key'))->toBeTrue();
});

it('keeps every row it was given', function (): void {
    $this->migratePath($this->currentMigrations());

    $fixture = new SuppressionDataFixture($this->isolated());
    $seeded = $fixture->seed(0);

    expect($seeded)->toBe(3);

    $before = $fixture->counts();

    // Re-running is a no-op rather than a rebuild. A host that has already
    // migrated must not have its rows dropped by a repeat run.
    $this->migratePath($this->currentMigrations());

    foreach ($before as $table => $count) {
        expect($this->isolated()->table($table)->count())
            ->toBeGreaterThanOrEqual($count, "rows disappeared from {$table}");
    }
});

it('installs onto a database that already has the tables, without touching them', function (): void {
    // The realistic case for a foundation package: a host that published the
    // migrations once, or a second copy of them arriving through a sibling.
    // The guard in each migration must make that a no-op rather than an abort,
    // because an abort here is a half-installed gate.
    $this->migratePath($this->currentMigrations());

    (new SuppressionDataFixture($this->isolated()))->seed(0);

    $this->isolated()->table('migrations')->delete();

    $this->migratePath($this->currentMigrations());

    expect($this->isolated()->table('suppressions')->count())->toBe(3)
        ->and($this->ranMigrations())->toContain('2026_07_30_000001_create_suppressions_table');
});

it('accepts many audit events with no dedupe key on a populated table', function (): void {
    // The NULL half of the uniqueness story, measured on the engine rather than
    // reasoned about: three manual events for one address, none of them keyed.
    $this->migratePath($this->currentMigrations());

    (new SuppressionDataFixture($this->isolated()))->seed(0);

    $unkeyed = $this->isolated()->table('suppression_events')->whereNull('dedupe_key')->count();

    expect($unkeyed)->toBe(2);

    $this->isolated()->table('suppression_events')->insert([
        'uuid' => (string) Str::uuid(),
        'brand_id' => 1,
        'email_normalized' => 'released@example.test',
        'event_type' => 'released',
        'dedupe_key' => null,
        'occurred_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($this->isolated()->table('suppression_events')->whereNull('dedupe_key')->count())->toBe(3);
});
