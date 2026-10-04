<?php

namespace Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Attendance\Exceptions\AttendanceException;
use Modules\Attendance\Http\AttendancePresenter;
use Modules\Attendance\Http\Controllers\Concerns\FindsWorkplace;
use Modules\Attendance\Http\Requests\DeviceImportRequest;
use Modules\Attendance\Http\Requests\LocationRequest;
use Modules\Attendance\Models\Location;
use Modules\Attendance\Services\DeviceImports;
use Modules\Attendance\Services\Locations;
use Modules\Attendance\Services\Workplace;

/**
 * Where people check in from: workplaces of the unit and the units around it
 * (read with attendance.view, changed with attendance.manage at the
 * workplace's unit), and attendance machines' files (attendance.manage).
 */
class WorkplaceController extends Controller
{
    use FindsWorkplace;

    public function __construct(
        private Workplace $workplace,
        private Locations $locations,
        private DeviceImports $devices,
        private AttendancePresenter $presenter,
    ) {}

    /** Workplaces of the unit, the units above it (they apply here) and below it. */
    public function locations(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('attendance.view', $unit);
        $units = [...$unit->ancestorIds(), ...$this->workplace->subtreeIds($unit)];
        $names = Organization::query()->whereKey($units)->get()->mapWithKeys(fn (Organization $item) => [$item->getKey() => $item->displayName()]);

        return response()->json([
            'data' => $this->workplace->query(Location::class, $company)->whereIn('unit_id', $units)->orderBy('name')->get()
                ->map(fn (Location $location) => $this->presenter->location($location, $names[$location->unit_id] ?? null))->values(),
            'meta' => ['required' => $this->locations->required($unit)],
        ]);
    }

    public function storeLocation(LocationRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $for = $request->validated('unit_id') ?? $unit->getKey();
        if (! $this->within($unit, $for)) {
            throw AttendanceException::notCompanyUnit();
        }
        $target = $this->unitOf($for);
        Gate::authorize('attendance.manage', $target);

        $location = $this->locations->create($company, $target, $request->safe()->only(['name', 'latitude_micro', 'longitude_micro', 'radius_m']), $request->user());

        return response()->json(['data' => $this->presenter->location($location, $target->displayName())], 201);
    }

    public function updateLocation(LocationRequest $request, string $organization, string $location): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->workplace->query(Location::class, $company)->whereKey($location)->first();
        if ($found === null || ! $this->within($unit, $found->unit_id)) {
            throw AttendanceException::locationNotFound();
        }
        $target = $this->unitOf($found->unit_id);
        Gate::authorize('attendance.manage', $target);
        $data = $request->validated();

        $changed = $this->locations->update($company, $found, (int) $data['base_version'], $data, $request->user());

        return response()->json(['data' => $this->presenter->location($changed, $target->displayName())]);
    }

    public function deviceFormat(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('attendance.manage', $unit);

        return response()->json(['data' => $this->devices->format($company)]);
    }

    /** A machine's file for the people of the unit (and below it). */
    public function importDevice(DeviceImportRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('attendance.manage', $unit);

        $result = $this->devices->import($company, $unit, $this->workplace->subtreeIds($unit), $request->file('file'),
            $request->validated('columns'), $request->validated('datetime_format'), $request->user());

        return response()->json(['data' => $result], 201);
    }
}
