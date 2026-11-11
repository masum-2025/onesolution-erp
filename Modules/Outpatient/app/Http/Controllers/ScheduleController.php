<?php

namespace Modules\Outpatient\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Outpatient\Exceptions\OutpatientException;
use Modules\Outpatient\Http\Controllers\Concerns\FindsOutpatient;
use Modules\Outpatient\Http\Requests\OutpatientActionRequest;
use Modules\Outpatient\Models\Chamber;
use Modules\Outpatient\Models\Doctor;
use Modules\Outpatient\Models\Schedule;
use Modules\Outpatient\Services\OutpatientStore;
use Modules\Outpatient\Services\Schedules;

/**
 * A doctor's weekly sessions and sign-in: read with outpatient.view, saved
 * with outpatient.manage. The staff list for the sign-in is shown only to
 * those who may save.
 */
class ScheduleController extends Controller
{
    use FindsOutpatient;

    public function __construct(private OutpatientStore $store, private Schedules $schedules) {}

    public function show(string $organization, string $doctor): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('outpatient.view', $unit);

        return response()->json(['data' => $this->view($unit, $company, $this->doctor($unit, $company, $doctor))]);
    }

    public function update(OutpatientActionRequest $request, string $organization, string $doctor): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->doctor($unit, $company, $doctor);
        Gate::authorize('outpatient.manage', $unit);
        $data = $request->validated();
        $saved = $this->schedules->save($company, $unit, $found, $data['sessions'], $data['user_id'] ?? null, (int) $data['base_version'], $request->user());

        return response()->json(['data' => $this->view($unit, $company, $saved)]);
    }

    private function doctor(Organization $unit, Organization $company, string $id): Doctor
    {
        return $this->store->query(Doctor::class, $company)->whereKey($id)->whereIn('unit_id', $this->store->subtreeIds($unit))->first()
            ?? throw OutpatientException::notFound('doctor');
    }

    /** @return array<string, mixed> */
    private function view(Organization $unit, Organization $company, Doctor $doctor): array
    {
        $manage = Gate::allows('outpatient.manage', $unit);

        return [
            'doctor' => ['id' => $doctor->getKey(), 'name' => $doctor->textIn('name'), 'specialty' => $doctor->specialty, 'user_id' => $doctor->user_id, 'version' => $doctor->version],
            'sessions' => $this->schedules->of($company, $doctor)->map(fn (Schedule $s) => [
                'id' => $s->getKey(), 'chamber_id' => $s->chamber_id, 'weekday' => $s->weekday, 'starts_at' => $s->starts_at, 'ends_at' => $s->ends_at, 'serial_limit' => $s->serial_limit,
            ])->values(),
            'chambers' => $this->store->query(Chamber::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Chamber $c) => ['id' => $c->getKey(), 'name' => $c->name, 'room' => $c->room])->values(),
            'staff' => $manage ? $this->schedules->staff($company)->map(fn ($user) => ['id' => $user->getKey(), 'name' => $user->name])->values() : [],
            'usual_limit' => (int) $this->store->rule('daily_serial_limit', $unit),
            'can' => ['manage' => $manage],
        ];
    }
}
