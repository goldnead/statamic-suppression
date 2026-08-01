<?php

namespace Goldnead\Suppression\Facades;

use Goldnead\Suppression\SuppressionService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Goldnead\Suppression\Models\Suppression|null suppress(string $email, string $reason, array $attributes = [])
 * @method static \Goldnead\Suppression\Models\Suppression release(string $email, array $context = [], ?int $brandId = null)
 * @method static \Goldnead\Suppression\Models\Suppression releaseComplaint(string $email, string $actor, string $reason, ?int $brandId = null, array $context = [])
 * @method static \Goldnead\Suppression\Models\Suppression|null recordSoftBounce(string $email, array $attributes = [])
 * @method static void recordDelivery(string $email, array $attributes = [])
 * @method static \Goldnead\Suppression\Models\Suppression|null find(string $email, ?int $brandId = null)
 * @method static \Illuminate\Support\Collection historyFor(string $email, ?int $brandId = null)
 * @method static int brandIdFor(string $reason, ?int $brandId = null)
 *
 * @see SuppressionService
 */
class Suppression extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'suppression';
    }
}
