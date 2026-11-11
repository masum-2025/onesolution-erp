<?php

namespace Modules\Outpatient\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Enums\MembershipStatus;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Outpatient\Exceptions\OutpatientException;
use Modules\Outpatient\Models\Chamber;
use Modules\Outpatient\Models\Doctor;
use Modules\Outpatient\Models\Schedule;

/**
 * A doctor's week and sign-in. The whole week is saved at once with the
 * doctor's version: a session kept is changed, a new one is added, one left
 * out is switched off (its serials keep their link). Chambers must be the
 * company's and within the unit. The sign-in is a staff member of the
 * company; with it the doctor sees their own patients. Audited.
 */
class Schedules
{
    public function __construct(private OutpatientStore $store, private AuditLogger $audit) {}

    /** @return Collection<int, Schedule> The doctor's sessions in use, by weekday and time. */
    public function of(Organization $company, Doctor $doctor): Collection
    {
        return $this->store->query(Schedule::class, $company)->where('doctor_id', $doctor->getKey())->where('is_active', true)
            ->orderBy('weekday')->orderBy('starts_at')->get();
    }

    /**
     * @param  list<array{id?: ?string, chamber_id: string, weekday: int, starts_at: string, ends_at: string, serial_limit?: ?int}>  $sessions
     */
    public function save(Organization $company, Organization $unit, Doctor $doctor, array $sessions, ?string $userId, int $baseVersion, User $actor): Doctor
    {
        $units = $this->store->subtreeIds($unit);
        $chambers = $this->store->query(Chamber::class, $company)->whereIn('unit_id', $units)->whereKey(array_column($sessions, 'chamber_id'))->pluck('id')->map(fn ($id) => (string) $id)->all();
        foreach ($sessions as $index => $session) {
            if (! in_array($session['chamber_id'], $chambers, true)) {
                throw ValidationException::withMessages(["sessions.{$index}.chamber_id" => __('outpatient::messages.errors.bad_link')]);
            }
            if ($session['ends_at'] <= $session['starts_at']) {
                throw ValidationException::withMessages(["sessions.{$index}.ends_at" => __('outpatient::messages.validation.ends_after_start')]);
            }
        }
        $this->noOverlap($sessions);
        if ($userId !== null && ! $this->isStaff($company, $userId)) {
            throw ValidationException::withMessages(['user_id' => __('outpatient::messages.validation.not_staff')]);
        }

        return $this->store->transaction($company, function () use ($company, $doctor, $sessions, $userId, $baseVersion, $actor) {
            $doctor = $this->store->query(Doctor::class, $company)->whereKey($doctor->getKey())->lockForUpdate()->firstOrFail();
            if ($doctor->version !== $baseVersion) {
                throw OutpatientException::versionConflict(['version' => $doctor->version]);
            }
            if ($userId !== null && $this->store->query(Doctor::class, $company)->where('user_id', $userId)->whereKeyNot($doctor->getKey())->exists()) {
                throw ValidationException::withMessages(['user_id' => __('outpatient::messages.validation.user_taken')]);
            }
            $current = $this->store->query(Schedule::class, $company)->where('doctor_id', $doctor->getKey())->get()->keyBy(fn (Schedule $s) => (string) $s->getKey());
            $kept = [];
            foreach ($sessions as $session) {
                $values = [
                    'chamber_id' => $session['chamber_id'], 'weekday' => (int) $session['weekday'], 'starts_at' => $session['starts_at'],
                    'ends_at' => $session['ends_at'], 'serial_limit' => $session['serial_limit'] ?? null, 'is_active' => true,
                ];
                $existing = isset($session['id']) ? $current->get($session['id']) : null;
                if ($existing !== null) {
                    $existing->fill($values)->save();
                    $kept[] = (string) $existing->getKey();
                } else {
                    $created = new Schedule(['organization_id' => $company->getKey(), 'doctor_id' => $doctor->getKey(), ...$values]);
                    $created->save();
                    $kept[] = (string) $created->getKey();
                }
            }
            $this->store->query(Schedule::class, $company)->where('doctor_id', $doctor->getKey())->whereNotIn('id', $kept)->update(['is_active' => false]);
            $old = ['user_id' => $doctor->user_id, 'sessions' => $current->where('is_active', true)->count()];
            $doctor->forceFill(['user_id' => $userId, 'version' => $doctor->version + 1])->save();
            $this->audit->record('outpatient.schedule_saved', $doctor, old: $old, new: ['user_id' => $userId, 'sessions' => count($kept)], actor: $actor, organizationId: $company->getKey());

            return $doctor;
        });
    }

    /** Staff of the company (owners and staff, not portal members) who may be a doctor's sign-in. */
    public function staff(Organization $company): Collection
    {
        $ids = OrganizationMembership::query()->whereIn('organization_id', $this->store->subtreeIds($company))
            ->where('status', MembershipStatus::Active)->where('membership_type', '!=', MembershipType::Portal->value)->pluck('user_id')->unique();

        return User::query()->whereKey($ids->all())->orderBy('name')->get(['id', 'name']);
    }

    private function isStaff(Organization $company, string $userId): bool
    {
        return OrganizationMembership::query()->where('user_id', $userId)->whereIn('organization_id', $this->store->subtreeIds($company))
            ->where('status', MembershipStatus::Active)->where('membership_type', '!=', MembershipType::Portal->value)->exists();
    }

    /** A doctor is in one place at a time: two sessions on a weekday may not overlap. */
    private function noOverlap(array $sessions): void
    {
        foreach ($sessions as $i => $a) {
            foreach ($sessions as $j => $b) {
                if ($j > $i && (int) $a['weekday'] === (int) $b['weekday'] && $a['starts_at'] < $b['ends_at'] && $b['starts_at'] < $a['ends_at']) {
                    throw ValidationException::withMessages(["sessions.{$j}.starts_at" => __('outpatient::messages.validation.overlap')]);
                }
            }
        }
    }
}
