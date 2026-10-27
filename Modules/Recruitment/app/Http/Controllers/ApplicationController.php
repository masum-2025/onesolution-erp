<?php

namespace Modules\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Recruitment\Http\Controllers\Concerns\FindsPlace;
use Modules\Recruitment\Http\RecruitmentPresenter;
use Modules\Recruitment\Http\Requests\ApplicationRequest;
use Modules\Recruitment\Http\Requests\CandidateRequest;
use Modules\Recruitment\Http\Requests\EndRequest;
use Modules\Recruitment\Http\Requests\HireRequest;
use Modules\Recruitment\Http\Requests\InterviewRequest;
use Modules\Recruitment\Models\Application;
use Modules\Recruitment\Services\Applications;
use Modules\Recruitment\Services\CvFiles;
use Modules\Recruitment\Services\Interviews;
use Modules\Recruitment\Services\Place;

/**
 * Applications at a unit (and below it), never unconfirmed ones: the list
 * (by opening, stage or status; a name or email typed in), one with the
 * person's files, history, interviews and feedback (an interviewer sees
 * only what they are asked to); adding someone by hand, moving and rating,
 * rejecting or withdrawing, files, interviews (recruitment.manage), and
 * hiring into HRM (recruitment.hire and hrm.manage where the job is).
 */
class ApplicationController extends Controller
{
    use FindsPlace;

    private const SEES = ['recruitment.view', 'recruitment.manage'];

    public function __construct(
        private Place $place,
        private Applications $applications,
        private Interviews $interviews,
        private RecruitmentPresenter $presenter,
    ) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any(self::SEES, $unit), 403);
        $filters = $request->validate([
            'opening_id' => ['nullable', 'string', 'size:26'],
            'stage' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', 'in:'.implode(',', array_diff(Application::STATUSES, [Application::UNCONFIRMED])).',all'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $filters['status'] ?? Application::ACTIVE;
        $list = $this->place->query(Application::class, $company)->whereIn('unit_id', $this->place->subtreeIds($unit))
            ->where('status', '!=', Application::UNCONFIRMED)
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($filters['opening_id'] ?? null, fn ($query, $id) => $query->where('opening_id', $id))
            ->when($filters['stage'] ?? null, fn ($query, $stage) => $query->where('stage', $stage))
            ->orderByDesc('created_at')->limit(500)->get();
        $rows = $this->presenter->applications($company, $list);
        // Names and emails are encrypted: matched here, not in the query.
        $q = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        if ($q !== '') {
            $rows = array_values(array_filter($rows, fn (array $row) => str_contains(mb_strtolower($row['name'].' '.$row['email'].' '.$row['phone']), $q)));
        }

        return response()->json(['data' => $rows]);
    }

    public function show(Request $request, string $organization, string $application): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $found = $this->applicationIn($unit, $company, $application);
        $where = $this->unitOf($found->unit_id);
        $sees = Gate::any(self::SEES, $where);
        abort_unless($sees || (Gate::allows('recruitment.interview', $where) && $this->interviews->isInterviewer($company, $found, $request->user())), 403);

        return response()->json(['data' => $this->detail($company, $found, $request->user())]);
    }

    public function store(CandidateRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $opening = $this->openingIn($unit, $company, $request->validated('opening_id'));
        Gate::authorize('recruitment.manage', $this->unitOf($opening->unit_id));
        $added = $this->applications->add($company, $opening, $request->validated(), $request->user());

        return response()->json(['data' => $this->detail($company, $added, $request->user())], 201);
    }

    public function update(ApplicationRequest $request, string $organization, string $application): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $found = $this->applicationIn($unit, $company, $application);
        Gate::authorize('recruitment.manage', $this->unitOf($found->unit_id));
        $saved = $this->applications->update($company, $found, $request->validated(), $request->user());

        return response()->json(['data' => $this->detail($company, $saved, $request->user())]);
    }

    /** reject | withdraw */
    public function end(EndRequest $request, string $organization, string $application, string $step): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(in_array($step, ['reject', 'withdraw'], true), 404);
        $found = $this->applicationIn($unit, $company, $application);
        Gate::authorize('recruitment.manage', $this->unitOf($found->unit_id));
        $status = $step === 'reject' ? Application::REJECTED : Application::WITHDRAWN;
        $done = $this->applications->end($company, $found, $status, (int) $request->validated('base_version'), (string) $request->validated('reason'), $request->boolean('tell'), $request->user());

        return response()->json(['data' => $this->detail($company, $done, $request->user())]);
    }

    public function hire(HireRequest $request, string $organization, string $application): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $found = $this->applicationIn($unit, $company, $application);
        $where = $this->unitOf($found->unit_id);
        Gate::authorize('recruitment.hire', $where);
        Gate::authorize('hrm.manage', $where);
        $hired = $this->applications->hire($company, $found, $request->validated(), $request->user());

        return response()->json(['data' => $this->detail($company, $hired, $request->user())]);
    }

    public function addFile(Request $request, string $organization, string $application, CvFiles $files): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $found = $this->applicationIn($unit, $company, $application);
        Gate::authorize('recruitment.manage', $this->unitOf($found->unit_id));
        $file = $request->validate(['file' => ['required', 'file']])['file'];
        $files->add($company, $found, $file, $request->user());

        return response()->json(['data' => $this->detail($company, $found, $request->user())], 201);
    }

    public function schedule(InterviewRequest $request, string $organization, string $application): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $found = $this->applicationIn($unit, $company, $application);
        Gate::authorize('recruitment.manage', $this->unitOf($found->unit_id));
        $this->interviews->schedule($company, $found, $request->validated(), $request->user());

        return response()->json(['data' => $this->detail($company, $found->refresh(), $request->user())], 201);
    }

    /** Whom one may ask to interview for this application. */
    public function interviewers(string $organization, string $application): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $found = $this->applicationIn($unit, $company, $application);
        $where = $this->unitOf($found->unit_id);
        Gate::authorize('recruitment.manage', $where);

        return response()->json(['data' => $this->interviews->interviewers($where)->map(fn (User $user) => ['id' => $user->getKey(), 'name' => $user->name])->values()]);
    }

    /** People employed where the job is (name and code only), to choose the new employee's manager when hiring. */
    public function people(Request $request, string $organization, string $application, EmployeeDirectory $directory): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $found = $this->applicationIn($unit, $company, $application);
        $where = $this->unitOf($found->unit_id);
        Gate::authorize('recruitment.hire', $where);
        $search = mb_strtolower(trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? '')));
        $people = collect($directory->inUnits($company, $this->place->subtreeIds($company), $this->place->today($company)))
            ->filter(fn ($employee) => $search === '' || str_contains(mb_strtolower($employee->name), $search) || str_contains(mb_strtolower($employee->code), $search))
            ->take(50)->map(fn ($employee) => ['id' => $employee->id, 'name' => $employee->name, 'code' => $employee->code])->values();

        return response()->json(['data' => $people]);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Organization $company, Application $application, User $viewer): array
    {
        $where = $this->unitOf($application->unit_id);
        $manage = Gate::allows('recruitment.manage', $where);
        $active = $application->status === Application::ACTIVE;

        return $this->presenter->application($company, $application, $viewer, $manage, [
            'manage' => $manage && $active,
            'hire' => $active && Gate::allows('recruitment.hire', $where) && Gate::allows('hrm.manage', $where),
            'feedback' => $this->interviews->isInterviewer($company, $application, $viewer),
            'contact' => $manage || Gate::allows('recruitment.view', $where),
        ]);
    }
}
