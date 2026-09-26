<?php

namespace App\Platform\Transfers\Console;

use App\Platform\Tenancy\Exceptions\TenancyException;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use App\Platform\Transfers\Services\TransferService;
use Illuminate\Console\Command;

/**
 * Platform operators move clients without their consent, e.g. when their
 * partner closes: one client, or every client of a partner. The reason is
 * recorded and the clients and both partners are told.
 *
 *   php artisan clients:transfer house --client=01J... --reason="Partner closed"
 *   php artisan clients:transfer house --all-from=acme --reason="Acme closed on 1 Nov"
 */
class MoveClients extends Command
{
    protected $signature = 'clients:transfer
        {to : Destination partner slug or id, or "house"}
        {--client= : One client (top organization id)}
        {--all-from= : Every client of this partner (slug or id)}
        {--reason= : Why (required, shown to the clients)}';

    protected $description = 'Move clients to another partner (platform decision)';

    public function handle(TransferService $transfers): int
    {
        $reason = trim((string) $this->option('reason'));
        if (mb_strlen($reason) < 5) {
            $this->error('Give a --reason of at least 5 characters.');

            return self::INVALID;
        }

        $to = $this->argument('to') === 'house'
            ? Partner::query()->where('is_house', true)->first()
            : $this->partner((string) $this->argument('to'));
        if ($to === null) {
            $this->error('No destination partner with that slug or id.');

            return self::INVALID;
        }

        if (($this->option('client') === null) === ($this->option('all-from') === null)) {
            $this->error('Give either --client or --all-from.');

            return self::INVALID;
        }

        $clients = Organization::query()->whereNull('parent_id');
        if ($this->option('client') !== null) {
            $clients->whereKey($this->option('client'));
        } else {
            $from = $this->partner((string) $this->option('all-from'));
            if ($from === null) {
                $this->error('No partner with that slug or id.');

                return self::INVALID;
            }
            $clients->where('partner_id', $from->getKey());
        }

        $moved = 0;
        $failed = 0;
        foreach ($clients->orderBy('created_at')->get() as $root) {
            try {
                $transfers->moveByPlatform($root, $to, $reason);
                $moved++;
                $this->line("  moved {$root->displayName()}");
            } catch (TenancyException $exception) {
                $failed++;
                $this->warn("  {$root->displayName()}: {$exception->userMessage()}");
            }
        }

        $this->info("{$moved} moved to {$to->name}, {$failed} not moved.");

        return $failed === 0 && $moved > 0 ? self::SUCCESS : self::FAILURE;
    }

    private function partner(string $key): ?Partner
    {
        return Partner::query()->where('slug', $key)->orWhere('id', $key)->first();
    }
}
