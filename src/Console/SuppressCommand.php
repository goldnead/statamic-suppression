<?php

namespace Goldnead\Suppression\Console;

use Goldnead\Suppression\Reasons;
use Goldnead\Suppression\SuppressionService;
use Illuminate\Console\Command;
use Throwable;

class SuppressCommand extends Command
{
    protected $signature = 'suppression:suppress
        {--email= : The address to block}
        {--reason=manual : One of hard_bounce, invalid_email, soft_bounce_threshold, complaint, manual, provider_import}
        {--brand= : Brand id for brand-scoped reasons; ignored for global ones}
        {--notes= : Free-text note stored on the row}
        {--actor= : Who is doing this; defaults to the OS user}';

    protected $description = 'Block an address from every send path.';

    public function handle(SuppressionService $suppressions): int
    {
        $email = (string) $this->option('email');
        $reason = (string) $this->option('reason');

        if ($email === '') {
            $this->error('--email is required.');

            return self::FAILURE;
        }

        try {
            $suppression = $suppressions->suppress($email, $reason, [
                'source' => 'cli',
                'brand_id' => $this->option('brand') !== null ? (int) $this->option('brand') : null,
                'notes' => $this->option('notes'),
                'actor' => $this->actor(),
            ]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // `suppress()` answers null when a redelivered provider event was
        // already recorded and no row is visible in the scope this reason
        // resolves to. Nothing was written and nothing is wrong — but there is
        // also nothing to report, and dereferencing it here was a fatal.
        if (! $suppression) {
            $this->warn("[{$email}] was already recorded for this event; nothing changed.");

            return self::FAILURE;
        }

        $scope = $suppression->isGlobal()
            ? 'globally (every brand)'
            : 'in brand '.$suppression->brand_id;

        $this->info("[{$suppression->email_normalized}] is now blocked {$scope} as {$reason}.");

        if (Reasons::isGlobal($reason) && $this->option('brand') !== null) {
            $this->line('Note: --brand was ignored — this reason is global by configuration.');
        }

        return self::SUCCESS;
    }

    protected function actor(): string
    {
        return (string) ($this->option('actor') ?: ('cli:'.(get_current_user() ?: 'unknown')));
    }
}
