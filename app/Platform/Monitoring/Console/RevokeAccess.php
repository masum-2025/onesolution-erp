<?php

namespace App\Platform\Monitoring\Console;

use App\Models\User;
use App\Platform\Monitoring\IncidentRevocation;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Console\Command;

/**
 * Incident response (docs/incident-playbook.md): cut every way in for a
 * person, an organization or a partner at once.
 */
class RevokeAccess extends Command
{
    protected $signature = 'security:revoke
        {--user= : A person (email or id)}
        {--organization= : An organization id (with its units)}
        {--partner= : A partner id or slug}
        {--reason= : Why (required; goes to the audit log)}
        {--force : Do not ask for confirmation}';

    protected $description = 'Revoke tokens, sessions, API keys and offline devices of a person, organization or partner';

    public function handle(IncidentRevocation $revocation): int
    {
        $targets = array_filter(['user' => $this->option('user'), 'organization' => $this->option('organization'), 'partner' => $this->option('partner')]);
        $reason = trim((string) $this->option('reason'));

        if (count($targets) !== 1) {
            $this->error('Give exactly one of --user, --organization or --partner.');

            return self::INVALID;
        }

        if (mb_strlen($reason) < 5) {
            $this->error('Give a --reason (at least 5 characters); it is kept in the audit log.');

            return self::INVALID;
        }

        $kind = array_key_first($targets);
        $value = (string) $targets[$kind];
        $target = match ($kind) {
            'user' => User::query()->where('email', $value)->orWhere('id', $value)->first(),
            'organization' => Organization::query()->find($value),
            'partner' => Partner::query()->where('id', $value)->orWhere('slug', $value)->first(),
        };

        if ($target === null) {
            $this->error("No {$kind} found for \"{$value}\".");

            return self::FAILURE;
        }

        $label = $target instanceof User ? $target->email : ($target instanceof Organization ? $target->displayName() : $target->name);
        if (! $this->option('force') && ! $this->confirm("Revoke every way in for {$kind} \"{$label}\"? People must sign in again.")) {
            $this->line('Nothing changed.');

            return self::FAILURE;
        }

        $counts = match ($kind) {
            'user' => $revocation->user($target, $reason),
            'organization' => $revocation->organization($target, $reason),
            'partner' => $revocation->partner($target, $reason),
        };

        $this->info('Revoked: '.implode(', ', array_map(fn (string $key, int $count) => "{$count} {$key}", array_keys($counts), $counts)).'.');

        return self::SUCCESS;
    }
}
