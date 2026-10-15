<?php

namespace Modules\Education\Setup;

use App\Platform\Modules\ModuleRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Modules\Education\Contracts\SetupStepProvider;
use Modules\Education\Models\Session;
use Modules\Education\Services\Education;

/**
 * One checklist of what an institution still has to set up, in the order
 * it is done: Education's own steps, then each module's that is on here
 * (providers tagged "education.setup_steps") in menu order. Optional steps help but do
 * not count towards ready.
 */
class SetupChecklist
{
    public const TAG = 'education.setup_steps';

    public function __construct(
        private EducationSetupSteps $own,
        private Education $education,
        private ModuleResolver $modules,
        private ModuleRegistry $registry,
    ) {}

    /**
     * @param  list<string>  $unitIds
     * @return array{session_id: ?string, groups: list<array<string, mixed>>, done: int, total: int, ready: bool}
     */
    public function for(Organization $company, Organization $unit, array $unitIds): array
    {
        $sessionId = $this->education->query(Session::class, $company)->where('status', 'open')->orderByDesc('starts_on')->value('id');
        $groups = [];
        foreach ([$this->own, ...app()->tagged(self::TAG)] as $provider) {
            if (! $provider instanceof SetupStepProvider || ! $this->modules->isEnabled($provider->module(), $unit)) {
                continue;
            }
            $steps = array_map(fn (array $step) => ['optional' => false, ...$step], $provider->steps($company, $sessionId, $unitIds));
            if ($steps !== []) {
                $groups[] = ['module' => $provider->module(), 'label' => $this->registry->get($provider->module())->label(), 'steps' => $steps];
            }
        }
        // Education first, then the modules as their menu orders them (the order in which they are set up).
        $rank = fn (array $group) => $group['module'] === $this->own->module() ? -1 : (collect($this->registry->get($group['module'])->menu)->min('order') ?? PHP_INT_MAX);
        usort($groups, fn (array $a, array $b) => $rank($a) <=> $rank($b));
        $required = collect($groups)->flatMap(fn (array $group) => $group['steps'])->where('optional', false);
        $done = $required->where('done', true)->count();

        return ['session_id' => $sessionId, 'groups' => $groups, 'done' => $done, 'total' => $required->count(), 'ready' => $done === $required->count()];
    }
}
