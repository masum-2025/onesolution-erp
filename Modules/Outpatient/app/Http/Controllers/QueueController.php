<?php

namespace Modules\Outpatient\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Outpatient\Exceptions\OutpatientException;
use Modules\Outpatient\Http\Controllers\Concerns\FindsOutpatient;
use Modules\Outpatient\Models\Chamber;
use Modules\Outpatient\Models\Doctor;
use Modules\Outpatient\Models\Serial;
use Modules\Outpatient\Services\OutpatientStore;
use Modules\Outpatient\Services\Serials;
use Modules\Outpatient\Services\SerialView;

/**
 * The waiting-room screen of a chamber (outpatient.view, on a signed-in
 * device) and a doctor's own patients (outpatient.consult). The screen shows
 * serial numbers only, never a patient's name.
 */
class QueueController extends Controller
{
    use FindsOutpatient;

    public function __construct(private OutpatientStore $store, private Serials $serials, private SerialView $view) {}

    public function queue(string $organization, string $chamber): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('outpatient.view', $unit);
        $found = $this->store->query(Chamber::class, $company)->whereKey($chamber)->whereIn('unit_id', $this->store->subtreeIds($unit))->first()
            ?? throw OutpatientException::notFound('chamber');
        $today = $this->store->today($company);
        $serials = $this->store->query(Serial::class, $company)->where('chamber_id', $found->getKey())->whereDate('date', $today->toDateString())
            ->whereIn('status', [Serial::BOOKED, Serial::ARRIVED, Serial::IN])->orderBy('serial_no')->get(['doctor_id', 'serial_no', 'status', 'called_at']);
        $doctors = $this->store->query(Doctor::class, $company)->whereKey($serials->pluck('doctor_id')->unique()->all())->get()->keyBy(fn ($d) => (string) $d->getKey());

        return response()->json(['data' => [
            'chamber' => ['name' => $found->name, 'room' => $found->room],
            'date' => $today->toDateString(),
            'doctors' => $serials->groupBy(fn ($s) => (string) $s->doctor_id)->map(fn ($list, $doctorId) => [
                'name' => $doctors->get($doctorId)?->textIn('name') ?? '',
                'now' => $list->where('status', Serial::IN)->sortByDesc('called_at')->pluck('serial_no')->values(),
                'waiting' => $list->where('status', Serial::ARRIVED)->pluck('serial_no')->values(),
                'booked' => $list->where('status', Serial::BOOKED)->count(),
            ])->values(),
        ]]);
    }

    public function mine(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('outpatient.consult', $unit);
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? $this->store->today($company)->toDateString();
        $doctors = $this->serials->doctorsOf($company, $request->user());
        $list = $doctors->isEmpty() ? collect() : $this->store->query(Serial::class, $company)->whereIn('unit_id', $this->store->subtreeIds($unit))
            ->whereIn('doctor_id', $doctors->all())->whereDate('date', $date)->whereIn('status', Serial::HOLDING)
            ->orderBy('schedule_id')->orderBy('serial_no')->limit(500)->get();

        return response()->json(['data' => $this->view->rows($company, $list), 'meta' => ['date' => $date, 'linked' => $doctors->isNotEmpty()]]);
    }
}
