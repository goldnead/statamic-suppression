<?php

use Goldnead\Suppression\Exceptions\ComplaintReleaseRefused;
use Goldnead\Suppression\Exceptions\IncompleteAuditTrail;
use Goldnead\Suppression\Models\Suppression;
use Goldnead\Suppression\Models\SuppressionEvent;
use Goldnead\Suppression\Reasons;
use Goldnead\Suppression\SuppressionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->service = app(SuppressionService::class);
});

/**
 * T3 — a complaint cannot be released through the ordinary path.
 *
 * Prevents: an editor undoing a legal block with one click on a filtered list.
 * The decision to allow a release at all opened a *separate* door; it did not
 * open this one, so this assertion outlives that decision rather than being
 * superseded by it.
 */
it('refuses a complaint on the ordinary release path and changes nothing', function (): void {
    $this->service->suppress('objector@example.test', Reasons::COMPLAINT);

    expect(fn () => $this->service->release('objector@example.test', ['actor' => 'user:1', 'reason' => 'Because I said so.']))
        ->toThrow(ComplaintReleaseRefused::class);

    $row = Suppression::query()->where('email_normalized', 'objector@example.test')->sole();

    expect($row->released_at)->toBeNull()
        ->and(SuppressionEvent::query()->where('event_type', SuppressionEvent::TYPE_RELEASED)->count())->toBe(0);
});

it('still releases an ordinary suppression through that path', function (): void {
    $this->service->suppress('bounced@example.test', Reasons::HARD_BOUNCE);

    $released = $this->service->release('bounced@example.test', [
        'actor' => 'user:1',
        'reason' => 'Mailbox was restored by the provider, confirmed by a test send.',
    ]);

    expect($released->released_at)->not->toBeNull()
        ->and($released->released_by)->toBe('user:1');
});

/**
 * T3b — the deliberate release is refused without a complete audit trail, and
 * recorded when it has one.
 *
 * Prevents: exactly the outcome the condition on this decision exists to rule
 * out — a complainant becoming mailable again with nobody's name, date or
 * stated grounds attached to the decision.
 */
it('refuses a deliberate complaint release with no actor', function (): void {
    $this->service->suppress('objector@example.test', Reasons::COMPLAINT);

    expect(fn () => $this->service->releaseComplaint('objector@example.test', '  ', 'A reason long enough to pass the minimum.'))
        ->toThrow(IncompleteAuditTrail::class);

    expect(Suppression::query()->where('email_normalized', 'objector@example.test')->sole()->released_at)->toBeNull()
        ->and(SuppressionEvent::query()->where('event_type', SuppressionEvent::TYPE_RELEASED)->count())->toBe(0);
});

it('refuses a deliberate complaint release whose reason is too short to mean anything', function (): void {
    $this->service->suppress('objector@example.test', Reasons::COMPLAINT);

    expect(fn () => $this->service->releaseComplaint('objector@example.test', 'user:1', 'ok'))
        ->toThrow(IncompleteAuditTrail::class);

    expect(Suppression::query()->where('email_normalized', 'objector@example.test')->sole()->released_at)->toBeNull()
        ->and(SuppressionEvent::query()->where('event_type', SuppressionEvent::TYPE_RELEASED)->count())->toBe(0);
});

it('records who, when and why when the release is complete', function (): void {
    $this->service->suppress('objector@example.test', Reasons::COMPLAINT);

    $reason = 'The recipient confirmed in writing on 2026-07-30 that the complaint was filed in error.';

    $released = $this->service->releaseComplaint('objector@example.test', 'user:17', $reason);

    expect($released->released_at)->not->toBeNull()
        ->and($released->released_by)->toBe('user:17')
        ->and($released->release_reason)->toBe($reason);

    $events = SuppressionEvent::query()
        ->where('email_normalized', 'objector@example.test')
        ->where('event_type', SuppressionEvent::TYPE_RELEASED)
        ->get();

    expect($events)->toHaveCount(1)
        ->and($events[0]->actor)->toBe('user:17')
        ->and($events[0]->payload['release_reason'])->toBe($reason)
        ->and($events[0]->reason)->toBe(Reasons::COMPLAINT);
});

/**
 * The transactional guarantee. A release that cannot be logged must not happen
 * — that is the whole condition, not a detail of it.
 */
it('rolls the release back when the audit event cannot be written', function (): void {
    $this->service->suppress('objector@example.test', Reasons::COMPLAINT);

    // The log is gone, so the event insert fails. If the state change survives
    // that, the address is mailable again with no record of the decision.
    Schema::drop('suppression_events');

    expect(fn () => $this->service->releaseComplaint(
        'objector@example.test',
        'user:1',
        'A stated reason that is comfortably longer than the configured minimum.',
    ))->toThrow(QueryException::class);

    expect(DB::table('suppressions')->where('email_normalized', 'objector@example.test')->value('released_at'))
        ->toBeNull('the release survived a failure to log it');
});

it('honours a configured minimum reason length', function (): void {
    config()->set('suppression.release.min_reason_length', 5);

    $this->service->suppress('objector@example.test', Reasons::COMPLAINT);

    $released = $this->service->releaseComplaint('objector@example.test', 'user:1', 'short');

    expect($released->released_at)->not->toBeNull();
});

/**
 * The history has to stay readable in full: blocked → released by X on D
 * because R → blocked again. A re-suppression that edited the release row away
 * would leave a record that reads as though the release never happened.
 */
it('keeps the release in the log when the address is blocked again', function (): void {
    $this->service->suppress('objector@example.test', Reasons::COMPLAINT);
    $this->service->releaseComplaint('objector@example.test', 'user:1', 'Complaint was filed against the wrong sender, verified.');
    $this->service->suppress('objector@example.test', Reasons::COMPLAINT);

    $types = SuppressionEvent::query()
        ->where('email_normalized', 'objector@example.test')
        ->orderBy('id')
        ->pluck('event_type')
        ->all();

    expect($types)->toBe([
        SuppressionEvent::TYPE_SUPPRESSED,
        SuppressionEvent::TYPE_RELEASED,
        SuppressionEvent::TYPE_SUPPRESSED,
    ]);

    $row = Suppression::query()->where('email_normalized', 'objector@example.test')->sole();

    expect($row->released_at)->toBeNull('the row is active again')
        ->and(app(\Goldnead\Suppression\Contracts\Gate::class)->isSuppressed('objector@example.test'))->toBeTrue();
});

it('will not let anything edit or delete an audit event', function (): void {
    $this->service->suppress('objector@example.test', Reasons::COMPLAINT);

    $event = SuppressionEvent::query()->sole();

    $event->actor = 'somebody-else';

    expect($event->save())->toBeFalse()
        ->and($event->delete())->toBeFalse()
        ->and(SuppressionEvent::query()->sole()->actor)->not->toBe('somebody-else');
});
