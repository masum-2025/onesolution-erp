<?php

namespace Modules\CourseRegistration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Portal\PortalAccess;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CourseRegistration\Exceptions\RegistrationException;
use Modules\CourseRegistration\Http\RegistrationPresenter;
use Modules\CourseRegistration\Models\Offering;
use Modules\CourseRegistration\Models\Registration;
use Modules\CourseRegistration\Models\RegistrationItem;
use Modules\CourseRegistration\Services\Campus;
use Modules\CourseRegistration\Services\Registrations;
use Modules\Education\Directory\AcademicDirectory;

/**
 * A student registering themselves in the client's portal (B2B2C): only
 * the student record the client linked to their login as "self" (never a
 * guardian's link), only in their current session, only while the window
 * is open and the rule course_registration.self_registration allows it.
 * The same checks as for staff apply (credits, prerequisites, seats).
 */
class PortalRegistrationController extends Controller
{
    public function __construct(
        private CurrentContext $context,
        private PortalAccess $portal,
        private Campus $campus,
        private Registrations $registrations,
        private AcademicDirectory $academic,
        private RegistrationPresenter $presenter,
    ) {}

    /** The student's session: the window, the limits, what is offered to them and their registration. */
    public function show(): JsonResponse
    {
        [$company, $student, $enrollment] = $this->me();
        $sessionId = $enrollment['session_id'];
        $registration = $this->campus->query(Registration::class, $company)->where('student_id', $student['id'])->where('session_id', $sessionId)->first();
        $items = $registration === null ? collect() : $this->registrations->items($company, $registration);
        $offerings = $this->campus->query(Offering::class, $company)->where('session_id', $sessionId)->where('status', 'open')
            ->whereIn('unit_id', [$enrollment['unit_id'], $company->getKey()])
            ->where(fn ($query) => $query->whereNull('level_id')->orWhere('level_id', $enrollment['level_id']))
            ->orderBy('kind')->orderBy('subject_id')->orderBy('group_name')->get();
        $subjects = $this->presenter->subjectsOf($company, [...$offerings, ...$items]);
        $taken = $this->campus->query(RegistrationItem::class, $company)->whereIn('offering_id', $offerings->pluck('id'))->where('status', 'registered')
            ->get(['offering_id'])->countBy('offering_id');
        $prerequisites = $this->academic->prerequisites($company, $offerings->pluck('subject_id')->unique()->values()->all());
        $completed = $this->campus->query(RegistrationItem::class, $company)->where('student_id', $student['id'])->where('outcome', 'completed')->pluck('subject_id')->all();
        $needed = $this->academic->subjects($company, array_values(array_unique(array_merge([], ...array_values($prerequisites)))));

        return response()->json(['data' => [
            'student' => array_intersect_key($student, array_flip(['id', 'code', 'name', 'name_local'])),
            'session' => $this->academic->session($company, $sessionId),
            'window' => $this->presenter->window($this->registrations->window($company, $sessionId)),
            'today' => $this->campus->today($company)->toDateString(),
            'rules' => $this->registrations->limits($company),
            'registration' => $registration === null ? null : $this->presenter->registration($registration, $items, $subjects, null, $offerings->keyBy('id')->all()),
            'offerings' => $offerings->map(fn (Offering $offering) => [
                ...$this->presenter->offering($offering, $subjects, (int) ($taken[$offering->getKey()] ?? 0)),
                // Subjects still to complete first (codes), so the screen can say why one cannot be chosen.
                'missing' => array_values(array_map(fn (string $id) => $needed[$id]['code'] ?? '?', array_diff($prerequisites[$offering->subject_id] ?? [], $completed))),
            ])->values(),
        ]]);
    }

    public function add(Request $request): JsonResponse
    {
        [$company, $student, $enrollment] = $this->me();
        $data = $request->validate(['offering_id' => ['required', 'string', 'size:26'], 'op_id' => ['nullable', 'string', 'max:64']]);
        // Checked before a registration is started, so a closed window leaves nothing behind.
        $this->registrations->assertWindowOpen($company, $enrollment['session_id']);
        $registration = $this->registrations->for($company, $student['id'], $enrollment['session_id'], $request->user());
        $this->registrations->add($company, $registration, $data['offering_id'], $request->user(), byStudent: true, source: 'student', opId: $data['op_id'] ?? null);

        return $this->show();
    }

    public function drop(Request $request, string $item): JsonResponse
    {
        [$company, $student] = $this->me();
        $found = $this->campus->query(RegistrationItem::class, $company)->whereKey($item)->where('student_id', $student['id'])->first()
            ?? throw RegistrationException::notFound('item');
        $data = $request->validate(['reason' => ['nullable', 'string', 'min:3', 'max:300']]);
        $this->registrations->drop($company, $found, $data['reason'] ?? null, $request->user(), byStudent: true);

        return $this->show();
    }

    public function submit(Request $request): JsonResponse
    {
        [$company, $student, $enrollment] = $this->me();
        $registration = $this->campus->query(Registration::class, $company)->where('student_id', $student['id'])->where('session_id', $enrollment['session_id'])->first()
            ?? throw RegistrationException::notFound('registration');
        $this->registrations->submit($company, $registration, $request->user(), byStudent: true);

        return $this->show();
    }

    /**
     * The institution, the student the login is (portal "self") and where they study now.
     *
     * @return array{0: Organization, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function me(): array
    {
        if (! $this->portal->isPortal()) {
            throw RegistrationException::notFound('student');
        }
        $membership = $this->context->membership();
        $studentId = PortalLink::query()->where('membership_id', $membership->getKey())->where('organization_id', $membership->organization_id)
            ->where('subject_type', 'education.student')->where('relation', 'self')->where('status', PortalLink::ACTIVE)->value('subject_id');
        $company = $this->campus->companyOf($this->context->organization());
        $student = $studentId === null ? null : $this->academic->student($company, $studentId);
        $enrollment = $student === null ? null : $this->academic->enrollment($company, $student['id']);
        if ($student === null || $enrollment === null) {
            throw RegistrationException::notFound('student');
        }

        return [$company, $student, $enrollment];
    }
}
