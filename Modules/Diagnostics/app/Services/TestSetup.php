<?php

namespace Modules\Diagnostics\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Modules\Diagnostics\Models\NormalRange;
use Modules\Diagnostics\Models\Parameter;
use Modules\Diagnostics\Models\Test;

/**
 * A test's parameters and normal ranges, saved together from the test's set-up
 * screen. Parameters sent with an id are changed, new ones made, missing ones
 * turned off (never deleted: old results point to them). Each parameter's
 * ranges are replaced by what was sent (results keep the flag they were
 * given). Audited with the test code.
 */
class TestSetup
{
    public function __construct(private DiagnosticsStore $store, private AuditLogger $audit) {}

    /**
     * @param  list<array<string, mixed>>  $parameters  Validated by ParametersRequest.
     */
    public function save(Organization $company, Test $test, array $parameters, User $actor): void
    {
        $this->store->transaction($company, function () use ($company, $test, $parameters, $actor) {
            $existing = $this->store->query(Parameter::class, $company)->where('test_id', $test->getKey())->get()->keyBy('id');
            $kept = [];
            foreach (array_values($parameters) as $sort => $data) {
                $parameter = isset($data['id']) ? ($existing[$data['id']] ?? null) : null;
                $parameter ??= new Parameter(['organization_id' => $company->getKey(), 'test_id' => $test->getKey()]);
                $parameter->fill([
                    'unit' => $data['unit'] ?? null, 'result_type' => $data['result_type'], 'choices' => $data['result_type'] === 'choice' ? array_values($data['choices'] ?? []) : null,
                    'decimals' => (int) ($data['decimals'] ?? 1), 'sort' => $sort, 'is_active' => true,
                ]);
                $parameter->putTexts('name', $data['name']);
                $parameter->save();
                $kept[] = $parameter->getKey();

                $this->store->query(NormalRange::class, $company)->where('parameter_id', $parameter->getKey())->delete();
                foreach ($data['ranges'] ?? [] as $range) {
                    (new NormalRange([
                        'organization_id' => $company->getKey(), 'parameter_id' => $parameter->getKey(), 'sex' => $range['sex'] ?? 'any',
                        'age_from_days' => (int) ($range['age_from_days'] ?? 0), 'age_to_days' => $range['age_to_days'] ?? null,
                        'low' => $range['low'] ?? null, 'high' => $range['high'] ?? null, 'critical_low' => $range['critical_low'] ?? null,
                        'critical_high' => $range['critical_high'] ?? null, 'normal_text' => $range['normal_text'] ?? null,
                    ]))->save();
                }
            }
            $this->store->query(Parameter::class, $company)->where('test_id', $test->getKey())->whereNotIn('id', $kept)->update(['is_active' => false]);
            $this->audit->record('diagnostics.test_parameters_saved', $test, new: ['code' => $test->code, 'parameters' => count($kept)], actor: $actor, organizationId: $company->getKey());
        });
    }

    /**
     * The test's parameters in use with their ranges, as arrays (for screens and flagging).
     *
     * @return list<array<string, mixed>>
     */
    public function of(Organization $company, string $testId, bool $activeOnly = true): array
    {
        $parameters = $this->store->query(Parameter::class, $company)->where('test_id', $testId)
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))->orderBy('sort')->orderBy('id')->get();
        $ranges = $parameters->isEmpty() ? collect() : $this->store->query(NormalRange::class, $company)->whereIn('parameter_id', $parameters->modelKeys())->get()->groupBy('parameter_id');

        return $parameters->map(fn (Parameter $parameter) => [
            'id' => $parameter->getKey(),
            'name' => $parameter->texts('name'),
            'unit' => $parameter->unit,
            'result_type' => $parameter->result_type,
            'choices' => $parameter->choices ?? [],
            'decimals' => $parameter->decimals,
            'is_active' => $parameter->is_active,
            'ranges' => ($ranges[$parameter->getKey()] ?? collect())->map(fn (NormalRange $range) => [
                'sex' => $range->sex, 'age_from_days' => $range->age_from_days, 'age_to_days' => $range->age_to_days,
                'low' => self::plain($range->low), 'high' => self::plain($range->high),
                'critical_low' => self::plain($range->critical_low), 'critical_high' => self::plain($range->critical_high), 'normal_text' => $range->normal_text,
            ])->values()->all(),
        ])->values()->all();
    }

    /** "12.5000" from the database as "12.5". */
    private static function plain(mixed $decimal): ?string
    {
        if ($decimal === null) {
            return null;
        }
        $text = (string) $decimal;

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
