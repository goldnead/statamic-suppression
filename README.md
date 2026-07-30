# Statamic Suppression

**The authoritative answer to "may we send to this address at all?"** — asked before a send is
constructed, shared by every addon in this family that puts mail in a queue.

It is not a deliverability dashboard and not an ESP abstraction. It owns two tables, one service and
one gate, and it deliberately knows nothing about who sends or how.

## Why it is its own package

A hard bounce is a property of the **mailbox**, not of the relationship that produced the send. That
sentence is the entire argument for this package existing separately.

If the suppression list lived inside the marketing addon, then whether an address was blocked would
depend on which addon happened to be sending — marketing mail gated, notification mail and weekly
digests not. The same dead mailbox would be written to anyway, from the same application, damaging
the same sending reputation. So the layer sits underneath both, next to `statamic-brand-context` and
`statamic-identity-contracts`, for the same reason those two do: several addons need the same
promise, and a promise that only one of them keeps is not one.

## Install

```bash
composer require goldnead/statamic-suppression
php artisan migrate
```

Requires `goldnead/statamic-brand-context`. Statamic itself is optional — the provider is a plain
Laravel one, so the gate works in a queue worker or a console command with no CP in sight.

## Usage

Ask before sending:

```php
use Goldnead\Suppression\Contracts\Gate;

if (app(Gate::class)->isSuppressed($email)) {
    return; // never construct the send
}
```

For an audience, ask once per batch rather than once per recipient:

```php
$blocked = app(Gate::class)->suppressedAmong($subscription->pluck('email'));

foreach ($subscriptions as $subscription) {
    if (isset($blocked[$subscription->email_normalized])) {
        continue;
    }
}
```

Record what a provider told you:

```php
use Goldnead\Suppression\Facades\Suppression;
use Goldnead\Suppression\Reasons;

Suppression::suppress($email, Reasons::HARD_BOUNCE, [
    'source' => 'resend',
    'provider' => 'resend',
    'provider_event_id' => $payload['data']['email_id'],
]);

// A single soft bounce is not a suppression. This counts it, and returns a
// suppression only when the run inside the window trips the threshold.
Suppression::recordSoftBounce($email, ['provider' => 'resend']);

// A successful delivery resets that window.
Suppression::recordDelivery($email);
```

## The rule that will surprise you

> **The gate falls closed, not open.** If it cannot answer, it throws `SuppressionCheckFailed` —
> it does not return `false`. A caller that catches that and sends anyway has converted a
> fail-closed gate into a fail-open one, which is the single defect this package exists to prevent.

This is deliberately the opposite of how a *segment* resolver should behave. A segment that cannot be
resolved falls open and the campaign goes to the whole list, and that is correct: a segment narrows
an audience, it never grants consent. Suppression is the other kind of check entirely. The two rules
look inconsistent side by side and they are not — anyone tempted to harmonise them should read this
paragraph first.

## Brands

The two models **do not use `HasBrand`**, and that is the one intentional exception in the family.

`BrandScope` adds `where brand_id = currentId()`. Applied here it would hide every global row
(`brand_id = 0`) from the gate query — a fail-open bug in precisely the query that must never fail
open — and its `creating` hook would stamp the current brand over the intended `0`. So the models
carry `scopeVisibleTo()` instead: global rows plus one brand's own, with `brand_id` set explicitly on
every write.

A future agent "fixing" these models for consistency would reintroduce both bugs.
`tests/Feature/CrossBrandTest.php` is what stops that.

### Which facts cross the boundary

| Reason | Scope | Why |
| --- | --- | --- |
| `hard_bounce` | **global** | The mailbox does not exist. It bounces identically from every brand, and every brand that re-learns it pays for the discovery in shared sending reputation. |
| `invalid_email` | **global** | Same fact, reported differently. |
| `soft_bounce_threshold` | **global** | A mailbox that failed five times in thirty days failed as a mailbox. |
| `provider_import` | **global** | The ESP is describing the address, not the relationship. |
| `complaint` | **brand** | The recipient objected to *this* sender. It says nothing about anybody else, and making it global would contradict the consent-bleed rule the subscription schema already carries. |
| `manual` | **brand** | An editorial act inside one brand's context. |

Reversible through `config/suppression.php`, never through a migration: `brand_id` is stored
explicitly and `0` simply means "every brand".

## Releasing

Suppressions are **released, not deleted**. `released_at` keeps the record and the event log keeps
the reasoning, so "blocked → released by X on D because R → blocked again" stays readable in full.

Complaints are the exception that proves the rule. `release()` refuses them:

```php
Suppression::release($email);                    // throws for reason = complaint
Suppression::releaseComplaint($email, $actor, $reason);   // the deliberate path
```

`releaseComplaint()` requires an actor and a stated reason clearing
`suppression.release.min_reason_length` (default 20), and writes the state change and the audit event
in **one transaction**. A release that cannot be logged does not happen. That is not a UI preference:
mailing a complainant again is the one failure mode here with regulatory exposure, and the point is
not to make it impossible but to make it impossible *by accident* and provable afterwards.

The actor must be the authenticated identifier taken from the request or the console — never from a
form field, because a form field is the attacker's to fill in. Store a stable id, not a display name.

## Console

```bash
php artisan suppression:suppress --email=… --reason=hard_bounce
php artisan suppression:release  --email=… --reason="…"
php artisan suppression:release  --email=… --force --reason="…"   # a complaint
```

## Schema

`suppressions` — current state, one row per (brand, address). `brand_id` is NOT NULL with a default
of `0`, because MySQL treats NULLs as distinct inside a unique index and a nullable "global" would
silently permit duplicate rows of the one fact the table exists to state once.

`suppression_events` — append-only history. `dedupe_key` is nullable *and* unique, which is the same
NULL rule read the other way: provider events carry a key so redelivery is harmless, manual actions
carry none so a second legitimate release is not swallowed as a duplicate of the first. The model
refuses updates and deletes.

## Tests

```bash
composer install
composer test
```

`vendor/bin/pest -c phpunit.mysql.xml` runs the identical suite against a real MySQL server. That run
matters more here than in most siblings: two of this package's guarantees are index behaviours
SQLite does not express the way InnoDB does. `tests/Unit/IndexKeyLengthTest.php` measures the key
arithmetic without a server, so CI does not depend on having one.

## License

MIT
