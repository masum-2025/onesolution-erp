<?php

namespace Modules\Letters\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Support\Http\PerPage;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Hrm\Directory\EmployeeRecord;
use Modules\Letters\Exceptions\LettersException;
use Modules\Letters\Http\Controllers\Concerns\FindsPlace;
use Modules\Letters\Http\LettersPresenter;
use Modules\Letters\Http\Requests\IssueRequest;
use Modules\Letters\Http\Requests\RevokeRequest;
use Modules\Letters\Models\Letter;
use Modules\Letters\Services\LetterFields;
use Modules\Letters\Services\Letters;
use Modules\Letters\Services\Place;

/**
 * Letters of the people of a unit (and below it): listed with
 * letters.view or letters.issue, issued and revoked with letters.issue.
 * A salary letter is opened only by people who may see salary in letters
 * (and by the employee it is for). "me" is one's own letters, the ones
 * shared with them, where the company lets employees see them.
 */
class LetterController extends Controller
{
    use FindsPlace;

    private const SEES = ['letters.view', 'letters.issue'];

    public function __construct(
        private Place $place,
        private Letters $letters,
        private LetterFields $fields,
        private EmployeeDirectory $directory,
        private LettersPresenter $presenter,
    ) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any(self::SEES, $unit), 403);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', 'string', 'max:30'],
            'employee_id' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', 'in:issued,revoked'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $page = $this->place->query(Letter::class, $company)->whereIn('unit_id', $this->place->subtreeIds($unit))
            ->when($filters['kind'] ?? null, fn ($query, $kind) => $query->where('kind', $kind))
            ->when($filters['employee_id'] ?? null, fn ($query, $id) => $query->where('employee_id', $id))
            ->when(($filters['status'] ?? null) === 'issued', fn ($query) => $query->whereNull('revoked_at'))
            ->when(($filters['status'] ?? null) === 'revoked', fn ($query) => $query->whereNotNull('revoked_at'))
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->where(fn ($inner) => $inner->where('number', 'like', "%{$q}%")
                ->orWhere('employee_name', 'like', "%{$q}%")->orWhere('employee_code', 'like', "%{$q}%")))
            ->orderByDesc('issued_at')->orderByDesc('number')
            ->paginate(PerPage::from($request, 25));

        return response()->json([
            'data' => $this->presenter->letters($page->items()),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(),
                'can_issue' => Gate::allows('letters.issue', $unit)],
        ]);
    }

    public function store(IssueRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        Gate::authorize('letters.issue', $unit);
        $letters = $this->letters->issue($unit, $company, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->letters($letters)], 201);
    }

    public function show(string $organization, string $letter): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any(self::SEES, $unit), 403);
        $found = $this->letterIn($unit, $company, $letter);
        if ($found->uses_salary && ! Gate::allows('letters.salary', $company)) {
            throw LettersException::salaryNotAllowed();
        }

        return response()->json(['data' => $this->presenter->letter($company, $found, [
            'revoke' => $found->revoked_at === null && Gate::allows('letters.issue', $unit),
        ])]);
    }

    public function revoke(RevokeRequest $request, string $organization, string $letter): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        Gate::authorize('letters.issue', $unit);
        $found = $this->letters->revoke($company, $this->letterIn($unit, $company, $letter), $request->validated('reason'), $request->user());

        return response()->json(['data' => $this->presenter->letter($company, $found, ['revoke' => false])]);
    }

    /** People of the unit (and below) employed today, to pick whom letters are for; by name or code. */
    public function employees(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        Gate::authorize('letters.issue', $unit);
        $q = mb_strtolower(trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? '')));
        $people = collect($this->directory->inUnits($company, $this->place->subtreeIds($unit), CarbonImmutable::now('UTC')))
            ->filter(fn (EmployeeRecord $employee) => $q === '' || str_contains(mb_strtolower($employee->name), $q) || str_contains(mb_strtolower($employee->code), $q))
            ->take(50)
            ->map(fn (EmployeeRecord $employee) => ['id' => $employee->id, 'code' => $employee->code, 'name' => $employee->name]);

        return response()->json(['data' => $people->values()]);
    }

    /** One's own letters (shared with them, not revoked), where the company lets employees see them. */
    public function mine(Request $request, string $organization): JsonResponse
    {
        [, $company] = $this->place($organization);
        $employee = $this->directory->forUser($company, $request->user());
        if ($employee === null || ! $this->letters->employeesSeeOwn($company)) {
            return response()->json(['data' => []]);
        }

        return response()->json(['data' => $this->presenter->letters($this->ownQuery($company, $employee->id)->orderByDesc('issued_at')->limit(100)->get())]);
    }

    public function mineShow(Request $request, string $organization, string $letter): JsonResponse
    {
        [, $company] = $this->place($organization);
        $employee = $this->directory->forUser($company, $request->user());
        $found = $employee === null || ! $this->letters->employeesSeeOwn($company) ? null : $this->ownQuery($company, $employee->id)->whereKey($letter)->first();

        return response()->json(['data' => $this->presenter->letter($company, $found ?? throw LettersException::letterNotFound())]);
    }

    /** @return Builder<Letter> */
    private function ownQuery(Organization $company, string $employeeId): Builder
    {
        return $this->place->query(Letter::class, $company)->where('employee_id', $employeeId)->where('shared', true)->whereNull('revoked_at');
    }
}
