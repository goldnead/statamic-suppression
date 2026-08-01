<?php

use Goldnead\Suppression\Models\Suppression;
use Goldnead\Suppression\Reasons;
use Goldnead\Suppression\SuppressionService;

/**
 * The two artisan commands are the only operating surface this package has:
 * there is no Control Panel screen, so `suppression:suppress` and
 * `suppression:release` are what a human actually touches. They are held to
 * the same invariants as the service, and they may not fall over.
 */
it('blocks an address globally for a global reason and reports the scope', function (): void {
    $this->artisan('suppression:suppress', ['--email' => ' Bounced@Example.TEST ', '--reason' => Reasons::HARD_BOUNCE])
        ->assertSuccessful();

    $row = Suppression::query()->where('email_normalized', 'bounced@example.test')->sole();

    expect($row->brand_id)->toBe(Reasons::GLOBAL_BRAND_ID)
        ->and($row->reason)->toBe(Reasons::HARD_BOUNCE);
});

it('refuses an unknown reason instead of writing a row nobody can interpret', function (): void {
    $this->artisan('suppression:suppress', ['--email' => 'x@example.test', '--reason' => 'because_i_said_so'])
        ->assertFailed();

    expect(Suppression::query()->count())->toBe(0);
});

/**
 * `suppress()` is documented as returning null, and it means it: a redelivered
 * provider event whose suppression row is not visible in the scope the reason
 * resolves to yields no row at all. The command dereferenced that return
 * without checking, so the one path the type system warned about was a fatal
 * rather than a message.
 */
it('survives a suppress() that returns no row', function (): void {
    $this->app->instance(SuppressionService::class, new class extends SuppressionService
    {
        public function suppress(string $email, string $reason, array $attributes = []): ?Suppression
        {
            return null;
        }
    });

    $this->artisan('suppression:suppress', ['--email' => 'ghost@example.test', '--reason' => Reasons::MANUAL])
        ->assertFailed();
});

it('releases an ordinary suppression and refuses a complaint without --force', function (): void {
    $service = app(SuppressionService::class);
    $service->suppress('manual@example.test', Reasons::MANUAL);
    $service->suppress('angry@example.test', Reasons::COMPLAINT);

    $this->artisan('suppression:release', ['--email' => 'manual@example.test', '--reason' => 'Editor blocked the wrong address.'])
        ->assertSuccessful();

    $this->artisan('suppression:release', ['--email' => 'angry@example.test', '--reason' => 'They asked us on the phone to resume.'])
        ->assertFailed();

    expect(Suppression::query()->where('email_normalized', 'manual@example.test')->sole()->released_at)->not->toBeNull()
        ->and(Suppression::query()->where('email_normalized', 'angry@example.test')->sole()->released_at)->toBeNull();
});

it('releases a complaint with --force, and records who and why', function (): void {
    app(SuppressionService::class)->suppress('angry@example.test', Reasons::COMPLAINT);

    $this->artisan('suppression:release', [
        '--email' => 'angry@example.test',
        '--force' => true,
        '--actor' => 'user:7',
        '--reason' => 'Written re-consent on file, ticket 1183, checked by Adrian.',
    ])->assertSuccessful();

    $row = Suppression::query()->where('email_normalized', 'angry@example.test')->sole();

    expect($row->released_at)->not->toBeNull()
        ->and($row->released_by)->toBe('user:7')
        ->and($row->release_reason)->toBe('Written re-consent on file, ticket 1183, checked by Adrian.');
});

it('refuses a forced complaint release whose stated reason is too short to mean anything', function (): void {
    app(SuppressionService::class)->suppress('angry@example.test', Reasons::COMPLAINT);

    $this->artisan('suppression:release', [
        '--email' => 'angry@example.test',
        '--force' => true,
        '--actor' => 'user:7',
        '--reason' => 'ok',
    ])->assertFailed();

    expect(Suppression::query()->where('email_normalized', 'angry@example.test')->sole()->released_at)->toBeNull();
});
