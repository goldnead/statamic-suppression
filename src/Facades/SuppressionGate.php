<?php

namespace Goldnead\Suppression\Facades;

use Goldnead\Suppression\Contracts\Gate;
use Illuminate\Support\Facades\Facade;

/**
 * @method static bool isSuppressed(string $email, ?int $brandId = null)
 * @method static array<string, true> suppressedAmong(iterable $emails, ?int $brandId = null)
 *
 * @see Gate
 */
class SuppressionGate extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'suppression.gate';
    }
}
