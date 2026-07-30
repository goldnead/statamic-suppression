<?php

namespace Goldnead\Suppression\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool isSuppressed(string $email, ?int $brandId = null)
 * @method static array<string, true> suppressedAmong(iterable $emails, ?int $brandId = null)
 *
 * @see \Goldnead\Suppression\Contracts\Gate
 */
class SuppressionGate extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'suppression.gate';
    }
}
