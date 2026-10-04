<?php

namespace Modules\Attendance\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Validation\ValidationException;
use Modules\Attendance\Exceptions\AttendanceException;
use Modules\Attendance\Models\Location;
use Modules\Attendance\Models\Punch;
use Modules\Attendance\Support\GeoDistance;

/**
 * Workplaces and the location check. A unit's workplaces (and those of the
 * units above it) are where its people may check in when the unit asks for
 * a location check (rule attendance.geo_fence_required). A phone's fix
 * vaguer than attendance.geo_max_accuracy_m is refused, so is one outside
 * every workplace (the person is told how far they are). Points of punches
 * are forgotten after attendance.location_retention_days.
 */
class Locations
{
    public function __construct(
        private Workplace $workplace,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, latitude_micro: int, longitude_micro: int, radius_m?: int|null}  $data
     */
    public function create(Organization $company, Organization $unit, array $data, User $actor): Location
    {
        $this->assertPoint($data['latitude_micro'], $data['longitude_micro']);

        return $this->workplace->transaction($company, function () use ($company, $unit, $data, $actor) {
            $location = new Location;
            $location->fill([
                'organization_id' => $company->getKey(), 'unit_id' => $unit->getKey(), 'name' => $data['name'],
                'latitude_micro' => $data['latitude_micro'], 'longitude_micro' => $data['longitude_micro'],
                'radius_m' => $data['radius_m'] ?? (int) $this->rules->get('attendance.geo_radius_m', $this->contexts->forOrganization($unit)),
                'is_active' => true, 'version' => 1,
            ])->save();
            $this->audit->record('attendance.location_added', $location, new: $this->values($location), actor: $actor, organizationId: $company->getKey());

            return $location;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Organization $company, Location $location, int $baseVersion, array $data, User $actor): Location
    {
        return $this->workplace->transaction($company, function () use ($company, $location, $baseVersion, $data, $actor) {
            /** @var Location $location */
            $location = $this->workplace->query(Location::class, $company)->whereKey($location->getKey())->lockForUpdate()->firstOrFail();
            if ($location->version !== $baseVersion) {
                throw AttendanceException::versionConflict(['version' => $location->version]);
            }
            $old = $this->values($location);
            $location->fill(array_intersect_key($data, array_flip(['name', 'latitude_micro', 'longitude_micro', 'radius_m', 'is_active'])));
            $this->assertPoint($location->latitude_micro, $location->longitude_micro);
            $location->version++;
            $location->save();
            $this->audit->record('attendance.location_updated', $location, old: $old, new: $this->values($location), actor: $actor, organizationId: $company->getKey());

            return $location;
        });
    }

    /** Whether people of the unit must check in from a workplace. */
    public function required(Organization $unit): bool
    {
        return (bool) $this->rules->get('attendance.geo_fence_required', $this->contexts->forOrganization($unit));
    }

    /**
     * Where a check-in was made, checked against the unit's workplaces:
     * the fields a punch keeps (point, accuracy, distance, workplace).
     *
     * @param  array{latitude_micro: int, longitude_micro: int, accuracy_m: int}|null  $fix
     * @return array{latitude_micro: int, longitude_micro: int, accuracy_m: int, distance_m: int, location_id: string}
     */
    public function check(Organization $company, Organization $unit, ?array $fix): array
    {
        if ($fix === null) {
            throw AttendanceException::locationNeeded();
        }
        $this->assertPoint($fix['latitude_micro'], $fix['longitude_micro']);
        $max = (int) $this->rules->get('attendance.geo_max_accuracy_m', $this->contexts->forOrganization($unit));
        if ($fix['accuracy_m'] > $max) {
            throw AttendanceException::locationVague($fix['accuracy_m'], $max);
        }

        $locations = $this->workplace->query(Location::class, $company)->where('is_active', true)
            ->whereIn('unit_id', [...$unit->ancestorIds(), $unit->getKey()])->get();
        if ($locations->isEmpty()) {
            throw AttendanceException::noWorkplaces();
        }

        $nearest = $locations->map(fn (Location $location) => [
            'location' => $location,
            'distance' => GeoDistance::metres($fix['latitude_micro'], $fix['longitude_micro'], $location->latitude_micro, $location->longitude_micro),
        ])->sortBy('distance')->first();
        if ($nearest['distance'] > $nearest['location']->radius_m) {
            throw AttendanceException::outsideWorkplace($nearest['distance'] - $nearest['location']->radius_m, $nearest['location']->name);
        }

        return [...$fix, 'distance_m' => $nearest['distance'], 'location_id' => $nearest['location']->getKey()];
    }

    /** Forget the points of punches older than the rule's days (distances stay). Returns how many. */
    public function forget(Organization $company): int
    {
        $days = (int) $this->rules->get('attendance.location_retention_days', $this->contexts->forOrganization($company));

        return $this->workplace->query(Punch::class, $company)->whereNotNull('latitude_micro')
            ->where('punched_at', '<', now()->subDays($days))
            ->update(['latitude_micro' => null, 'longitude_micro' => null]);
    }

    private function assertPoint(int $latitude, int $longitude): void
    {
        if (! GeoDistance::valid($latitude, $longitude)) {
            throw ValidationException::withMessages(['latitude_micro' => __('attendance::attendance.validation.point')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Location $location): array
    {
        return [
            'name' => $location->name, 'unit_id' => $location->unit_id, 'latitude_micro' => $location->latitude_micro,
            'longitude_micro' => $location->longitude_micro, 'radius_m' => $location->radius_m, 'is_active' => $location->is_active,
        ];
    }
}
