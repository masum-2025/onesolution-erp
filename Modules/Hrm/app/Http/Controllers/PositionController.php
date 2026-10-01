<?php

namespace Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Hrm\Exceptions\HrmException;
use Modules\Hrm\Http\Controllers\Concerns\FindsHrmRecords;
use Modules\Hrm\Http\EmployeePresenter;
use Modules\Hrm\Http\Requests\PositionRequest;
use Modules\Hrm\Models\Position;

/**
 * Positions usable at the organization in the address: its own, those of
 * the units above it, and (with descendants) those below. Never deleted once
 * people hold them: marked inactive instead.
 */
class PositionController extends Controller
{
    use FindsHrmRecords;

    public function __construct(private EmployeePresenter $presenter, private AuditLogger $audit) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('hrm.view', $organization);

        $ids = [...$organization->ancestorIds(), ...$this->subtreeIds($organization)];

        return response()->json(['data' => Position::query()->whereIn('organization_id', $ids)
            ->when(! $request->boolean('all'), fn ($query) => $query->where('is_active', true))
            ->orderBy('code')->get()
            ->map(fn (Position $position) => $this->presenter->position($position))->values()]);
    }

    public function store(PositionRequest $request, string $organization): JsonResponse
    {
        $unit = $this->unitIn($this->findVisible($organization), $request->validated('organization_id'));
        Gate::authorize('hrm.manage', $unit);
        $data = $request->validated();

        $position = new Position;
        $position->fill(['organization_id' => $unit->getKey(), 'code' => $data['code'] ?? null, 'grade' => $data['grade'] ?? null, 'is_active' => $data['is_active'] ?? true, 'version' => 1]);
        $position->putTexts('title', $data['title'])->save();

        $this->audit->record('hrm.position_created', $position, new: ['code' => $position->code, 'title' => $position->texts('title')], actor: $request->user(), organizationId: $unit->getKey());

        return response()->json(['data' => $this->presenter->position($position)], 201);
    }

    public function update(PositionRequest $request, string $organization, string $position): JsonResponse
    {
        $organization = $this->findVisible($organization);
        $found = Position::query()->whereKey($position)->first();
        if ($found === null || ! $this->inside($organization, $found->organization_id)) {
            throw HrmException::positionNotFound();
        }
        Gate::authorize('hrm.manage', Organization::query()->findOrFail($found->organization_id));

        $data = $request->validated();
        if ($found->version !== (int) $data['base_version']) {
            throw HrmException::versionConflict($this->presenter->position($found));
        }

        $old = ['code' => $found->code, 'grade' => $found->grade, 'is_active' => $found->is_active, 'title' => $found->texts('title')];
        $found->fill(array_intersect_key($data, array_flip(['code', 'grade', 'is_active'])));
        if (isset($data['title'])) {
            $found->putTexts('title', $data['title']);
        }
        $found->version = $found->version + 1;
        $found->save();

        $this->audit->record('hrm.position_updated', $found, old: $old, new: ['code' => $found->code, 'grade' => $found->grade, 'is_active' => $found->is_active, 'title' => $found->texts('title')], actor: $request->user(), organizationId: $found->organization_id);

        return response()->json(['data' => $this->presenter->position($found)]);
    }
}
