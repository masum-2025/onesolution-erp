<?php

namespace Modules\Letters\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Portal\PortalAccess;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Letters\Exceptions\LettersException;
use Modules\Letters\Http\LettersPresenter;
use Modules\Letters\Models\Letter;
use Modules\Letters\Services\Letters;
use Modules\Letters\Services\Place;

/**
 * A portal member's own letters (B2B2C: an employee who uses the client's
 * portal): the letters shared with the employee records the client linked
 * to them (portal subject hrm.employee), not revoked, where the company
 * lets employees see them. Read only.
 */
class PortalLetterController extends Controller
{
    public function __construct(
        private CurrentContext $context,
        private PortalAccess $portal,
        private Place $place,
        private EmployeeDirectory $directory,
        private Letters $letters,
        private LettersPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        $company = $this->company();
        if ($company === null) {
            return response()->json(['data' => []]);
        }
        $data = [];
        foreach ($this->directory->many($company, $this->portal->subjectIds('hrm.employee')) as $employee) {
            $data[] = [
                'employee' => ['id' => $employee->id, 'name' => $employee->name],
                'letters' => $this->presenter->letters($this->own($company, [$employee->id])->orderByDesc('issued_at')->limit(100)->get()),
            ];
        }

        return response()->json(['data' => $data]);
    }

    public function show(string $letter): JsonResponse
    {
        $company = $this->company();
        $found = $company === null ? null : $this->own($company, $this->portal->subjectIds('hrm.employee'))->whereKey($letter)->first();

        return response()->json(['data' => $this->presenter->letter($company, $found ?? throw LettersException::letterNotFound())]);
    }

    private function company(): ?Organization
    {
        if (! $this->portal->isPortal()) {
            return null;
        }
        $company = $this->place->companyOf($this->context->organization());

        return $this->letters->employeesSeeOwn($company) ? $company : null;
    }

    /**
     * @param  list<string>  $employeeIds
     * @return Builder<Letter>
     */
    private function own(Organization $company, array $employeeIds): Builder
    {
        return $this->place->query(Letter::class, $company)->whereIn('employee_id', $employeeIds)->where('shared', true)->whereNull('revoked_at');
    }
}
