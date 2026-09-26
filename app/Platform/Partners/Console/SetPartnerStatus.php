<?php

namespace App\Platform\Partners\Console;

use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Enums\PartnerStatus;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Console\Command;

/**
 * Platform operators suspend or reactivate a partner (e.g. unpaid invoices).
 * Suspended: the partner console closes; its clients keep reading and
 * exporting for the grace period (partners.suspension_grace_days), then may
 * only export. Nothing is ever deleted.
 *
 *   php artisan partners:status suspend acme --reason="Invoices unpaid since June"
 *   php artisan partners:status reactivate acme --reason="Paid in full"
 */
class SetPartnerStatus extends Command
{
    protected $signature = 'partners:status
        {action : suspend or reactivate}
        {partner : Partner slug or id}
        {--reason= : Why (required, stored in the audit log)}';

    protected $description = 'Suspend or reactivate a partner';

    public function handle(AuditLogger $audit): int
    {
        $action = (string) $this->argument('action');
        $reason = (string) $this->option('reason');

        if (! in_array($action, ['suspend', 'reactivate'], true)) {
            $this->error('The action must be suspend or reactivate.');

            return self::INVALID;
        }

        if (mb_strlen($reason) < 5) {
            $this->error('Give a --reason of at least 5 characters.');

            return self::INVALID;
        }

        $partner = Partner::query()->where('slug', $this->argument('partner'))->orWhere('id', $this->argument('partner'))->first();

        if ($partner === null || $partner->is_house) {
            $this->error($partner?->is_house ? 'The house partner cannot be suspended.' : 'No partner with that slug or id.');

            return self::INVALID;
        }

        $old = ['status' => $partner->status->value, 'suspended_at' => $partner->suspended_at?->toIso8601String()];

        $partner->forceFill($action === 'suspend'
            ? ['status' => PartnerStatus::Suspended, 'suspended_at' => $partner->suspended_at ?? now()]
            : ['status' => PartnerStatus::Active, 'suspended_at' => null])->save();

        $audit->record(
            action: $action === 'suspend' ? 'partner.suspended' : 'partner.reactivated',
            target: $partner,
            old: $old,
            new: ['status' => $partner->status->value, 'suspended_at' => $partner->suspended_at?->toIso8601String()],
            reason: $reason,
            partnerId: $partner->getKey(),
        );

        $this->info("{$partner->name} is now {$partner->status->value}.");

        return self::SUCCESS;
    }
}
