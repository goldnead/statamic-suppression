<?php

namespace Goldnead\Suppression\Console;

use Goldnead\Suppression\Exceptions\ComplaintReleaseRefused;
use Goldnead\Suppression\Reasons;
use Goldnead\Suppression\SuppressionService;
use Illuminate\Console\Command;
use Throwable;

/**
 * The scripted counterpart to the Control Panel's release flow.
 *
 * It obeys exactly the same invariants: `--force` is required to touch a
 * complaint, and even then the actor and the stated reason are mandatory. The
 * actor comes from the OS user or an explicit flag rather than from a session,
 * because there is no session here — but it is still recorded, because "who"
 * does not become optional just for being inconvenient to determine.
 */
class ReleaseCommand extends Command
{
    protected $signature = 'suppression:release
        {--email= : The address to unblock}
        {--brand= : Restrict to one brand; defaults to global + current brand}
        {--force : Required to release a complaint suppression}
        {--reason= : Why this is being released. Mandatory for a complaint}
        {--actor= : Who is doing this; defaults to the OS user}';

    protected $description = 'Release a suppression, with the audit trail a complaint release requires.';

    public function handle(SuppressionService $suppressions): int
    {
        $email = (string) $this->option('email');

        if ($email === '') {
            $this->error('--email is required.');

            return self::FAILURE;
        }

        $brandId = $this->option('brand') !== null ? (int) $this->option('brand') : null;
        $existing = $suppressions->find($email, $brandId);

        if (! $existing) {
            $this->error("No suppression found for [{$email}].");

            return self::FAILURE;
        }

        try {
            if ($existing->reason === Reasons::COMPLAINT) {
                if (! $this->option('force')) {
                    throw ComplaintReleaseRefused::for($email);
                }

                $suppressions->releaseComplaint(
                    $email,
                    $this->actor(),
                    (string) $this->option('reason'),
                    $brandId,
                );

                $this->warn(
                    "[{$email}] complained about mail from this brand and is now mailable again. ".
                    'The decision is recorded with your identifier, the time and your stated reason.'
                );

                return self::SUCCESS;
            }

            $suppressions->release($email, [
                'actor' => $this->actor(),
                'reason' => $this->option('reason'),
            ], $brandId);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("[{$email}] released.");

        return self::SUCCESS;
    }

    protected function actor(): string
    {
        return (string) ($this->option('actor') ?: ('cli:'.(get_current_user() ?: 'unknown')));
    }
}
