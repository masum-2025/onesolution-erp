<?php

namespace Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Crm\Http\Controllers\Concerns\FindsCrm;
use Modules\Crm\Http\CrmPresenter;
use Modules\Crm\Http\Requests\ActivityRequest;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;
use Modules\Crm\Services\Activities;
use Modules\Crm\Services\Crm;

/**
 * Activities of the unit in the address and below: a contact's history, or
 * a person's follow-ups (mine: overdue, today, coming; others' with
 * crm.manage). Read with crm.view, written with crm.edit.
 */
class ActivityController extends Controller
{
    use FindsCrm;

    public function __construct(private Crm $crm, private Activities $activities, private CrmPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('crm.view', $unit);
        $filters = $request->validate(['contact_id' => ['nullable', 'string', 'max:26'], 'when' => ['nullable', 'in:overdue,today,upcoming,done'], 'assigned_to' => ['nullable', 'string', 'max:26']]);
        $me = $request->user()->getKey();
        $person = $filters['assigned_to'] ?? $me;
        if ($person !== $me) {
            Gate::authorize('crm.manage', $unit);
        }
        $zone = $this->crm->timezone($company);
        $start = CarbonImmutable::now($zone)->startOfDay()->utc();
        $end = CarbonImmutable::now($zone)->endOfDay()->utc();
        $query = $this->crm->query(Activity::class, $company)->whereIn('unit_id', $this->unitIds($unit));
        if (! empty($filters['contact_id'])) {
            $query->where('contact_id', $filters['contact_id'])->orderByDesc('created_at');
        } else {
            $query->where('assigned_to', $person);
            match ($filters['when'] ?? 'today') {
                'overdue' => $query->whereNull('done_at')->where('due_at', '<', $start)->orderBy('due_at'),
                'today' => $query->whereNull('done_at')->whereBetween('due_at', [$start, $end])->orderBy('due_at'),
                'upcoming' => $query->whereNull('done_at')->where('due_at', '>', $end)->orderBy('due_at'),
                'done' => $query->whereNotNull('done_at')->where('done_at', '>=', now()->subDays(30))->orderByDesc('done_at'),
            };
        }
        $activities = $query->limit(300)->get();
        $contacts = $this->crm->query(Contact::class, $company)->whereKey($activities->pluck('contact_id')->unique()->all())->get(['id', 'name', 'phone'])->keyBy('id');
        $counts = $this->crm->query(Activity::class, $company)->whereIn('unit_id', $this->unitIds($unit))->where('assigned_to', $person)->whereNull('done_at')->whereNotNull('due_at');

        return response()->json([
            'data' => $activities->map(fn (Activity $activity) => [...$this->presenter->activity($activity), 'contact_name' => $contacts[$activity->contact_id]->name ?? null, 'contact_phone' => $contacts[$activity->contact_id]->phone ?? null])->values(),
            'meta' => ['overdue' => (clone $counts)->where('due_at', '<', $start)->count(), 'today' => (clone $counts)->whereBetween('due_at', [$start, $end])->count()],
        ]);
    }

    public function store(ActivityRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('crm.edit', $unit);
        $data = $request->validated();
        $this->recordIn(Contact::class, $unit, $company, $data['contact_id'], 'contact');
        $made = $this->activities->create($company, $data, $request->user());

        return response()->json(['data' => $this->presenter->activity($made)], $made->wasRecentlyCreated ? 201 : 200);
    }

    public function update(ActivityRequest $request, string $organization, string $activity): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('crm.edit', $unit);
        $found = $this->recordIn(Activity::class, $unit, $company, $activity, 'activity');
        $data = $request->validated();

        return response()->json(['data' => $this->presenter->activity($this->activities->update($company, $found, (int) $data['base_version'], $data, $request->user()))]);
    }
}
