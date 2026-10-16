<?php

namespace Modules\FactoryErp\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\FactoryErp\Exceptions\FactoryException;
use Modules\FactoryErp\Models\Line;

/**
 * Sewing lines: each belongs to a factory (the unit it was made at), with
 * a code unique in the company, a name and how many machines it has.
 * Switched off rather than removed, so past output keeps its line.
 */
class Lines
{
    public function __construct(private Floor $floor, private AuditLogger $audit) {}

    /**
     * Lines of the unit and the units below it.
     *
     * @return Collection<int, Line>
     */
    public function of(Organization $company, Organization $unit, bool $activeOnly = false): Collection
    {
        return $this->floor->query(Line::class, $company)->whereIn('unit_id', $this->floor->subtreeIds($unit))
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))->orderBy('code')->get();
    }

    /**
     * @param  array<string, mixed>  $data  Validated by LineRequest.
     */
    public function save(Organization $company, Organization $unit, ?Line $line, array $data, User $actor): Line
    {
        return $this->floor->transaction($company, function () use ($company, $unit, $line, $data, $actor) {
            if ($line !== null) {
                $line = $this->floor->query(Line::class, $company)->whereKey($line->getKey())->lockForUpdate()->firstOrFail();
                if ($line->version !== (int) $data['base_version']) {
                    throw FactoryException::versionConflict(['version' => $line->version]);
                }
                $old = $line->only(['code', 'name', 'machines', 'is_active']);
                $line->fill(array_intersect_key($data, array_flip(['code', 'name', 'machines', 'is_active'])));
                $line->version++;
            } else {
                $old = null;
                $line = new Line(['organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), ...array_intersect_key($data, array_flip(['code', 'name', 'machines']))]);
            }
            $line->code = mb_strtoupper(trim($line->code));
            if ($this->floor->query(Line::class, $company)->where('code', $line->code)->when($line->exists, fn ($query) => $query->whereKeyNot($line->getKey()))->exists()) {
                throw ValidationException::withMessages(['code' => __('factory_erp::factory.validation.code_taken')]);
            }
            $line->save();
            $this->audit->record($old === null ? 'factory_erp.line_created' : 'factory_erp.line_updated', $line, old: $old ?? [],
                new: $line->only(['code', 'name', 'machines', 'is_active']), actor: $actor, organizationId: $company->getKey());

            return $line;
        });
    }
}
