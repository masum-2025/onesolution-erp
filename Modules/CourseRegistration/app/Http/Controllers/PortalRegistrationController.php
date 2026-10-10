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
 * A parent linked to the student may look at the registration, never change it.
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

    /**
     * The student's session: the window, the limits, what is offered to them
     * and their registration. With ?record= (their portal record) a parent
     * sees their child's registration, read-only; changes are the student's.
     */
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate(['record' => ['nullable', 'string', 'size:26']]);

        return $this->view(...$this->me($data['record'] ?? null));
    }

    public function add(Request $request): JsonResponse
    {
        [$company, $student, $enrollment] = $this->me();
        $data = $request->validate(['offering_id' => ['required', 'string', 'size:26'], 'op_id' => ['nullable', 'string', 'max:64']]);
        // Checked before a registration is started, so a closed window leaves nothing behind.
        $this->registrations->assertWindowOpen($company, $enrollment['session_id']);
        $registration = $this->registrations->for($company, $student['id'], $enrollment['session_id'], $request->user());
        $this->registrations->add($company, $registration, $data['offering_id'], $request->user(), byStudent: true, source: 'student', opId: $data['op_id'] ?? null);

        return $this->view($company, $student, $enrollment);
    }

    public function drop(Request $request, string $item): JsonResponse
    {
        [$company, $student, $enrollment] = $this->me();
        $found = $this->campus->query(RegistrationItem::class, $company)->whereKey($item)->where('student_id', $student['id'])->first()
            ?? throw RegistrationException::notFound('item');
        $data = $request->validate(['reason' => ['nullable', 'string', 'min:3', 'max:300']]);
        $this->registrations->drop($company, $found, $data['reason'] ?? null, $request->user(), byStudent: true);

        return $this->view($company, $student, $enrollment);
    }

    public function submit(Request $request): JsonResponse
    {
        [$company, $student, $enrollment] = $this->me();
        $registration = $this->campus->query(Registration::class, $company)->where('student_id', $student['id'])->where('session_id', $enrollment['session_id'])->first()
            ?? throw RegistrationException::notFound('registration');
        $this->registrations->submit($company, $registration, $request->user(), byStudent: true);

        return $this->view($company, $student, $enrollment);
    }

    /**
     * @param  array<string, mixed>  $student
     * @param  array<string, mixed>  $enrollment
     */
    private function view(Organization $company, array $student, array $enrollment, bool $own = true): JsonResponse
    {
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
        $rules = $this->registrations->limits($company);
        $prerequisites = $rules['prerequisites_enforced'] ? $this->academic->prerequisites($company, $offerings->pluck('subject_id')->unique()->values()->all()) : [];
        $completed = $this->campus->query(RegistrationItem::class, $company)->where('student_id', $student['id'])->where('outcome', 'completed')->pluck('subject_id')->all();
        $needed = $this->academic->subjects($company, array_values(array_unique(array_merge([], ...array_values($prerequisites)))));
        $can = [
            'add' => $own && $this->registrations->windowOpen($company, $sessionId),
            'drop' => $own && $this->registrations->windowOpen($company, $sessionId, forDrop: true),
        ];
        $active = $items->whereIn('status', RegistrationItem::ACTIVE);
        $limit = $rules['max_credits_centi'] > 0 ? $rules['max_credits_centi'] + $rules['overload_credits_centi'] : null;
        // Place on the waiting list, counted from the first who waits.
        $waiting = $items->where('status', 'waitlisted');
        $positions = [];
        if ($waiting->isNotEmpty()) {
            $queue = $this->campus->query(RegistrationItem::class, $company)->whereIn('offering_id', $waiting->pluck('offering_id'))
                ->where('status', 'waitlisted')->orderBy('waitlisted_at')->orderBy('id')->get(['id', 'offering_id']);
            foreach ($queue->groupBy('offering_id') as $rows) {
                foreach ($rows->values() as $index => $row) {
                    $positions[$row->id] = $index + 1;
                }
            }
        }
        $shown = $registration === null ? null : $this->presenter->registration($registration, $items, $subjects, null, $offerings->keyBy('id')->all());
        if ($shown !== null) {
            $shown['items'] = $shown['items']->map(fn (array $item) => [...$item, 'position' => $positions[$item['id']] ?? null]);
        }

        return response()->json(['data' => [
            'student' => array_intersect_key($student, array_flip(['id', 'code', 'name', 'name_local'])),
            'own' => $own,
            'can' => $can,
            'session' => $this->academic->session($company, $sessionId),
            'window' => $this->presenter->window($this->registrations->window($company, $sessionId)),
            'today' => $this->campus->today($company)->toDateString(),
            'rules' => $rules,
            'registration' => $shown,
            'offerings' => $offerings->map(function (Offering $offering) use ($subjects, $taken, $prerequisites, $completed, $needed, $active, $registration, $limit, $rules) {
                $seats = (int) ($taken[$offering->getKey()] ?? 0);
                // Subjects still to complete first (codes), so the screen can say why one cannot be chosen.
                $missing = array_values(array_map(fn (string $id) => $needed[$id]['code'] ?? '?', array_diff($prerequisites[$offering->subject_id] ?? [], $completed)));
                $full = $seats >= $offering->capacity;
                $reason = match (true) {
                    $active->contains('subject_id', $offering->subject_id) => 'taken',
                    $missing !== [] => 'prerequisites',
                    $full && ! $rules['waitlist'] => 'full',
                    ! $full && $limit !== null && ($registration?->credits_centi ?? 0) + $offering->credits_centi > $limit => 'credits',
                    default => null,
                };

                return [
                    ...$this->presenter->offering($offering, $subjects, $seats),
                    'missing' => $missing,
                    // Why it cannot be chosen now (null: it can; with "waitlist" it goes on the waiting list).
                    'reason' => $reason,
                    'waitlist' => $reason === null && $full,
                ];
            })->values(),
        ]]);
    }

    /**
     * The institution, the student and where they study now: the login's own
     * student record ("self"), or with $record a record linked to this login
     * in any relation (a parent's child), then only to look at.
     *
     * @return array{0: Organization, 1: array<string, mixed>, 2: array<string, mixed>, 3: bool}
     */
    private function me(?string $record = null): array
    {
        if (! $this->portal->isPortal()) {
            throw RegistrationException::notFound('student');
        }
        $membership = $this->context->membership();
        $link = PortalLink::query()->where('membership_id', $membership->getKey())->where('organization_id', $membership->organization_id)
            ->where('subject_type', 'education.student')->where('status', PortalLink::ACTIVE)
            ->when($record === null, fn ($query) => $query->where('relation', 'self'), fn ($query) => $query->whereKey($record))
            ->first(['subject_id', 'relation']);
        $company = $this->campus->companyOf($this->context->organization());
        $student = $link === null ? null : $this->academic->student($company, $link->subject_id);
        $enrollment = $student === null ? null : $this->academic->enrollment($company, $student['id']);
        if ($student === null || $enrollment === null) {
            throw RegistrationException::notFound('student');
        }

        return [$company, $student, $enrollment, $link->relation === 'self'];
    }
}
