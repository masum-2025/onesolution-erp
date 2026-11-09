<?php

namespace Modules\AiAssistant\Services;

use App\Platform\Ai\AiReading;
use App\Platform\Ai\Contracts\ReadsText;
use App\Platform\Ai\Exceptions\AiUnavailable;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\AiAssistant\Models\AiUsage;

/**
 * The AI assistant's public service (SC-4): other modules ask it to read a
 * text into a fixed shape. It reads only for a company that turned the
 * module on (with its consent), within rule ai_assistant.monthly_calls, with
 * the model of rule ai_assistant.model, and counts every reading per module
 * and day (numbers only, never the text). Null means "go on without AI".
 */
class Understanding
{
    public function __construct(
        private ReadsText $reader,
        private ModuleResolver $modules,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /** Whether a reading may be asked for now (module on, month not used up). */
    public function available(Organization $company): bool
    {
        return $this->modules->isEnabled('ai_assistant', $company) && $this->left($company) > 0;
    }

    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     * @param  array<string, mixed>  $schema
     */
    public function read(Organization $company, string $module, string $instructions, array $messages, array $schema): ?AiReading
    {
        if (! $this->available($company)) {
            return null;
        }
        $name = (string) $this->rules->get('ai_assistant.model', $this->contexts->forOrganization($company));
        $model = (string) (config("ai.models.{$name}") ?? config('ai.models.haiku'));

        try {
            $reading = $this->reader->read($instructions, $messages, $schema, $model);
        } catch (AiUnavailable) {
            $this->count($company, $module, ['failures' => 1]);

            return null;
        }
        $this->count($company, $module, ['calls' => 1, 'input_tokens' => $reading->inputTokens, 'output_tokens' => $reading->outputTokens]);

        return $reading;
    }

    /** Readings left this month (failed ones count too: they may cost). */
    public function left(Organization $company): int
    {
        $limit = (int) $this->rules->get('ai_assistant.monthly_calls', $this->contexts->forOrganization($company));

        return max(0, $limit - $this->usedThisMonth($company));
    }

    public function usedThisMonth(Organization $company): int
    {
        $start = CarbonImmutable::now('UTC')->startOfMonth()->toDateString();
        $month = fn () => $this->usage($company)->where('day', '>=', $start);

        return (int) $month()->sum('calls') + (int) $month()->sum('failures');
    }

    /**
     * Usage of the last days, newest first, for the usage screen.
     *
     * @return list<array{day: string, module: string, calls: int, failures: int, input_tokens: int, output_tokens: int}>
     */
    public function history(Organization $company, int $days = 31): array
    {
        $from = CarbonImmutable::now('UTC')->subDays($days - 1)->toDateString();

        return $this->usage($company)->where('day', '>=', $from)->orderByDesc('day')->orderBy('module')->get()
            ->map(fn (AiUsage $row) => [
                'day' => $row->day->toDateString(), 'module' => $row->module, 'calls' => $row->calls, 'failures' => $row->failures,
                'input_tokens' => $row->input_tokens, 'output_tokens' => $row->output_tokens,
            ])->all();
    }

    // ---------------------------------------------------------------- inside

    /** @return Builder<AiUsage> */
    private function usage(Organization $company): Builder
    {
        return AiUsage::inTenantOf($company)->withoutGlobalScope(OrganizationScope::class)->where('organization_id', $company->getKey());
    }

    /**
     * @param  array<string, int>  $add
     */
    private function count(Organization $company, string $module, array $add): void
    {
        $day = CarbonImmutable::now('UTC')->toDateString();
        $bump = fn (): int => $this->usage($company)->where('module', $module)->whereDate('day', $day)->toBase()->incrementEach($add, ['updated_at' => now()]);
        if ($bump() > 0) {
            return;
        }
        try {
            $this->usage($company)->create(['organization_id' => $company->getKey(), 'module' => $module, 'day' => $day, ...$add]);
        } catch (UniqueConstraintViolationException) {
            $bump();
        }
    }
}
