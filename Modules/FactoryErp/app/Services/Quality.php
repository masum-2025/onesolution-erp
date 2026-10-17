<?php

namespace Modules\FactoryErp\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\FactoryErp\Exceptions\FactoryException;
use Modules\FactoryErp\Models\DefectType;
use Modules\FactoryErp\Models\Inspection;
use Modules\FactoryErp\Models\InspectionDefect;
use Modules\FactoryErp\Models\Line;
use Modules\FactoryErp\Models\Order;

/**
 * Quality on the floor:
 *
 * - End-line checks: a line's pieces checked and the defects found by kind;
 *   DHU (defects per hundred units) = defects × 100 ÷ checked.
 * - Final inspection of an order: the sample size and the most defects a
 *   passing sample may have come from the AQL table (database/data/aql.php)
 *   for the lot and the company's AQL (rule factory_erp.final_aql); more
 *   fails it. Where the company says so (factory_erp.ship_needs_final_pass)
 *   an order ships only after its last final inspection passed.
 *
 * Kinds of defect are the company's own (a starter list on first use).
 * A wrong inspection is voided with a reason and stops counting.
 */
class Quality
{
    public function __construct(
        private Floor $floor,
        private AuditLogger $audit,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /**
     * The company's kinds of defect (the starter list the first time).
     *
     * @return Collection<int, DefectType>
     */
    public function defectTypes(Organization $company): Collection
    {
        $types = $this->floor->query(DefectType::class, $company)->orderBy('sort_order')->orderBy('code')->get();
        if ($types->isNotEmpty()) {
            return $types;
        }
        $this->floor->transaction($company, function () use ($company) {
            foreach (require module_path('FactoryErp', 'database/data/defect-types.php') as $order => $starter) {
                $type = new DefectType(['organization_id' => $company->getKey(), 'code' => $starter['code'], 'sort_order' => $order]);
                $type->putTexts('name', $starter['name'])->save();
            }
        });

        return $this->floor->query(DefectType::class, $company)->orderBy('sort_order')->orderBy('code')->get();
    }

    /**
     * @param  array<string, mixed>  $data  Validated by DefectTypeRequest.
     */
    public function saveDefectType(Organization $company, ?DefectType $type, array $data, User $actor): DefectType
    {
        return $this->floor->transaction($company, function () use ($company, $type, $data) {
            if ($type !== null) {
                $type = $this->floor->query(DefectType::class, $company)->whereKey($type->getKey())->lockForUpdate()->firstOrFail();
                if ($type->version !== (int) $data['base_version']) {
                    throw FactoryException::versionConflict(['version' => $type->version]);
                }
                $type->fill(array_intersect_key($data, array_flip(['code', 'sort_order', 'is_active'])));
                $type->version++;
            } else {
                $type = new DefectType(['organization_id' => $company->getKey(), 'code' => $data['code'], 'sort_order' => $data['sort_order'] ?? 100]);
            }
            $type->code = mb_strtoupper(trim($type->code));
            if (isset($data['name'])) {
                $type->putTexts('name', $data['name']);
            }
            if ($this->floor->query(DefectType::class, $company)->where('code', $type->code)->when($type->exists, fn ($query) => $query->whereKeyNot($type->getKey()))->exists()) {
                throw ValidationException::withMessages(['code' => __('factory_erp::factory.validation.code_taken')]);
            }
            $type->save();

            return $type;
        });
    }

    /**
     * Sample size and the most defects a passing sample may have, for a lot at an AQL.
     *
     * @return array{0: int, 1: int}
     */
    public static function sample(int $lotSize, string $aql): array
    {
        foreach ((require module_path('FactoryErp', 'database/data/aql.php'))['levels'] as [$upTo, $sample, $accept]) {
            if ($upTo === null || $lotSize <= $upTo) {
                return [min($sample, $lotSize), $accept[$aql] ?? throw FactoryException::notFound('aql')];
            }
        }

        throw FactoryException::notFound('aql');
    }

    /** Defects per hundred units, in basis points of a unit (320 = 3.2). */
    public static function dhuBp(int $defects, int $checked): ?int
    {
        return $checked > 0 ? intdiv($defects * 10000, $checked) : null;
    }

    /**
     * @param  array<string, mixed>  $data  Validated by InspectionRequest.
     */
    public function record(Organization $company, Organization $unit, array $data, User $actor): Inspection
    {
        if (($data['op_id'] ?? null) !== null) {
            $done = $this->floor->query(Inspection::class, $company)->where('op_id', $data['op_id'])->first();
            if ($done !== null) {
                return $done;
            }
        }
        $units = $this->floor->subtreeIds($unit);
        $order = $this->floor->query(Order::class, $company)->whereKey($data['order_id'])->whereIn('unit_id', $units)->first() ?? throw FactoryException::notFound('order');
        if ($order->status !== 'in_production') {
            throw FactoryException::wrongStatus();
        }
        $final = $data['kind'] === 'final';
        $line = null;
        if (! $final) {
            $line = $this->floor->query(Line::class, $company)->whereKey($data['line_id'] ?? '')->whereIn('unit_id', $units)->first();
            if ($line === null || ! $line->is_active) {
                throw FactoryException::lineOff();
            }
        }
        $today = $this->floor->today($company);
        $on = isset($data['inspected_on']) ? CarbonImmutable::parse($data['inspected_on'], 'UTC') : $today;
        if ($on->greaterThan($today)) {
            throw FactoryException::futureDate('inspected_on');
        }
        $counts = [];
        foreach ($data['defects'] ?? [] as $defect) {
            $counts[$defect['defect_type_id']] = ($counts[$defect['defect_type_id']] ?? 0) + (int) $defect['count'];
        }
        $counts = array_filter($counts);
        $known = $this->defectTypes($company)->where('is_active', true)->modelKeys();
        if (array_diff(array_keys($counts), $known) !== []) {
            throw FactoryException::notFound('defect_type');
        }
        $defects = array_sum($counts);
        $aql = null;
        $accept = null;
        if ($final) {
            $aql = (string) $this->rules->get('factory_erp.final_aql', $this->contexts->forOrganization($unit));
            [$checked, $accept] = self::sample((int) $data['lot_size'], $aql);
        } else {
            $checked = (int) $data['checked'];
        }

        return $this->floor->transaction($company, function () use ($company, $order, $line, $data, $on, $counts, $defects, $final, $checked, $aql, $accept, $actor, $unit) {
            $inspection = $this->floor->query(Inspection::class, $company)->create([
                'organization_id' => $company->getKey(), 'unit_id' => $line?->unit_id ?? $unit->getKey(), 'order_id' => $order->getKey(), 'line_id' => $line?->getKey(),
                'kind' => $data['kind'], 'inspected_on' => $on->toDateString(), 'hour' => $final ? null : ($data['hour'] ?? null), 'checked' => $checked, 'defects' => $defects,
                'lot_size' => $final ? (int) $data['lot_size'] : null, 'aql' => $final ? $aql : null, 'accept' => $final ? $accept : null,
                'result' => $final ? ($defects <= $accept ? 'pass' : 'fail') : null,
                'note' => $data['note'] ?? null, 'created_by' => $actor->getKey(), 'op_id' => $data['op_id'] ?? null,
            ]);
            foreach ($counts as $typeId => $count) {
                $this->floor->query(InspectionDefect::class, $company)->create([
                    'organization_id' => $company->getKey(), 'inspection_id' => $inspection->getKey(), 'defect_type_id' => $typeId, 'count' => $count,
                ]);
            }
            if ($final) {
                $this->audit->record('factory_erp.final_inspected', $inspection,
                    new: ['order' => $order->number, 'lot' => $inspection->lot_size, 'sample' => $checked, 'defects' => $defects, 'accept' => $inspection->accept, 'result' => $inspection->result],
                    actor: $actor, organizationId: $company->getKey());
            }

            return $inspection;
        });
    }

    /** A wrong inspection stops counting. */
    public function void(Organization $company, Inspection $inspection, int $baseVersion, string $reason, User $actor): Inspection
    {
        return $this->floor->transaction($company, function () use ($company, $inspection, $baseVersion, $reason, $actor) {
            $inspection = $this->floor->query(Inspection::class, $company)->whereKey($inspection->getKey())->lockForUpdate()->firstOrFail();
            if ($inspection->version !== $baseVersion) {
                throw FactoryException::versionConflict(['version' => $inspection->version]);
            }
            $order = $this->floor->query(Order::class, $company)->whereKey($inspection->order_id)->firstOrFail();
            if ($inspection->voided_at !== null || $order->status !== 'in_production') {
                throw FactoryException::wrongStatus();
            }
            $inspection->forceFill(['voided_at' => now(), 'voided_by' => $actor->getKey(), 'void_reason' => $reason, 'version' => $inspection->version + 1])->save();
            $this->audit->record('factory_erp.inspection_voided', $inspection, old: ['kind' => $inspection->kind, 'defects' => $inspection->defects, 'result' => $inspection->result],
                new: ['voided' => true], reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $inspection;
        });
    }

    /**
     * @param  list<string>  $inspectionIds
     * @return Collection<string, Collection<int, InspectionDefect>>
     */
    public function defectsOf(Organization $company, array $inspectionIds): Collection
    {
        return $this->floor->query(InspectionDefect::class, $company)->whereIn('inspection_id', $inspectionIds)->get()->groupBy('inspection_id');
    }

    /** The order's last final inspection (not voided), if any. */
    public function lastFinal(Organization $company, Order $order): ?Inspection
    {
        return $this->floor->query(Inspection::class, $company)->where('order_id', $order->getKey())->where('kind', 'final')->whereNull('voided_at')
            ->orderByDesc('created_at')->orderByDesc('id')->first();
    }

    /**
     * An order's end-line quality: pieces checked, defects, DHU, and defects by kind.
     *
     * @return array{checked: int, defects: int, dhu_bp: int|null, by_type: array<string, int>}
     */
    public function endline(Organization $company, Order $order): array
    {
        $inspections = $this->floor->query(Inspection::class, $company)->where('order_id', $order->getKey())->where('kind', 'endline')->whereNull('voided_at')->get();
        $byType = $this->floor->query(InspectionDefect::class, $company)->whereIn('inspection_id', $inspections->modelKeys())->get()
            ->groupBy('defect_type_id')->map(fn ($defects) => (int) $defects->sum('count'))->sortDesc()->all();
        $checked = (int) $inspections->sum('checked');
        $defects = (int) $inspections->sum('defects');

        return ['checked' => $checked, 'defects' => $defects, 'dhu_bp' => self::dhuBp($defects, $checked), 'by_type' => $byType];
    }

    /**
     * Today's end-line checks per line at the units: checked and defects.
     *
     * @param  list<string>  $units
     * @return array<string, array{checked: int, defects: int}>
     */
    public function todayByLine(Organization $company, array $units): array
    {
        return $this->floor->query(Inspection::class, $company)->whereIn('unit_id', $units)->where('kind', 'endline')->whereNull('voided_at')
            ->where('inspected_on', $this->floor->today($company)->toDateString())->get(['line_id', 'checked', 'defects'])->groupBy('line_id')
            ->map(fn ($checks) => ['checked' => (int) $checks->sum('checked'), 'defects' => (int) $checks->sum('defects')])->all();
    }
}
