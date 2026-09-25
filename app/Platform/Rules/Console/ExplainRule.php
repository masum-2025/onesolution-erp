<?php

namespace App\Platform\Rules\Console;

use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ExplainRule extends Command
{
    protected $signature = 'rules:explain
        {key : Rule key}
        {--organization= : Organization id (default: platform level)}
        {--date= : Resolve as of this date (UTC)}';

    protected $description = 'Show how a rule value is resolved, level by level';

    public function handle(RuleResolver $resolver, RuleContextFactory $contexts): int
    {
        $context = $this->option('organization')
            ? $contexts->forOrganization(Organization::query()->findOrFail($this->option('organization')))
            : $contexts->platform();

        $asOf = $this->option('date') ? Carbon::parse((string) $this->option('date'), 'UTC') : null;
        $resolved = $resolver->explain((string) $this->argument('key'), $context, $asOf);

        $this->table(
            ['Level', 'Name', 'Set', 'Lock', 'Constrain', 'Note'],
            array_map(fn (array $entry) => [
                $entry['level'],
                $entry['name'] ?? '',
                json_encode($entry['set'] ?? ($entry['value'] ?? null)),
                json_encode($entry['lock'] ?? null),
                json_encode($entry['constrain'] ?? null),
                $entry['note'] ?? '',
            ], $resolved->trace),
        );

        $this->info('Effective value: '.json_encode($resolved->value).' (from '.($resolved->sourceLevel ?? 'default').')');

        return self::SUCCESS;
    }
}
