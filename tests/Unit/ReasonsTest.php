<?php

use Goldnead\Suppression\Reasons;
use Goldnead\Suppression\Support\EmailNormalizer;

it('splits deliverability facts from consent facts', function (): void {
    expect(Reasons::isGlobal(Reasons::HARD_BOUNCE))->toBeTrue()
        ->and(Reasons::isGlobal(Reasons::INVALID_EMAIL))->toBeTrue()
        ->and(Reasons::isGlobal(Reasons::SOFT_BOUNCE_THRESHOLD))->toBeTrue()
        ->and(Reasons::isGlobal(Reasons::PROVIDER_IMPORT))->toBeTrue()
        ->and(Reasons::isGlobal(Reasons::COMPLAINT))->toBeFalse()
        ->and(Reasons::isGlobal(Reasons::MANUAL))->toBeFalse();
});

it('rejects a reason it does not know', function (): void {
    expect(fn () => Reasons::assertKnown('because'))->toThrow(InvalidArgumentException::class);
});

it('rejects a scope that is neither global nor brand', function (): void {
    config()->set('suppression.scopes.manual', 'sometimes');

    expect(fn () => Reasons::scopeFor(Reasons::MANUAL))->toThrow(InvalidArgumentException::class);
});

it('trims and lowercases an address and nothing else', function (): void {
    // No Gmail dot-stripping and no plus-address folding: those rules are right
    // for one provider and wrong for business mailboxes, and a suppression
    // layer that over-matches blocks people who never bounced.
    expect(EmailNormalizer::normalize('  Adrian.Goldner+news@Example.TEST '))
        ->toBe('adrian.goldner+news@example.test')
        ->and(EmailNormalizer::normalize('   '))->toBeNull()
        ->and(EmailNormalizer::normalize(null))->toBeNull();
});

it('deduplicates a batch while normalising it', function (): void {
    expect(EmailNormalizer::normalizeMany(['A@b.test', 'a@B.test', ' a@b.test ', '', null, 42]))
        ->toBe(['a@b.test']);
});
