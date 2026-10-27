<?php

namespace Modules\Recruitment\Services;

use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Validation\ValidationException;

/**
 * The steps an application goes through at a company, in order (rule
 * recruitment.stages, per country, partner or company): the first is
 * where new applications start. Hiring and rejecting end an application
 * at whatever stage it is; they are not stages.
 */
class Stages
{
    public function __construct(private RuleResolver $rules, private RuleContextFactory $contexts) {}

    /**
     * @return list<array{key: string, name: array<string, string>}>
     */
    public function of(Organization $company): array
    {
        $stages = [];
        foreach ((array) $this->rules->get('recruitment.stages', $this->contexts->forOrganization($company)) as $stage) {
            $key = (string) ($stage['key'] ?? '');
            if ($key !== '' && ! isset($stages[$key])) {
                $stages[$key] = ['key' => $key, 'name' => (array) ($stage['name'] ?? [])];
            }
        }

        return array_values($stages);
    }

    public function first(Organization $company): string
    {
        return $this->of($company)[0]['key'] ?? 'applied';
    }

    /** A stage of the company, or a validation error on "stage". */
    public function check(Organization $company, string $key): string
    {
        if (! in_array($key, array_column($this->of($company), 'key'), true)) {
            throw ValidationException::withMessages(['stage' => __('recruitment::recruitment.validation.stage')]);
        }

        return $key;
    }
}
