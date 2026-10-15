<?php

namespace Modules\Education\Contracts;

use App\Platform\Tenancy\Models\Organization;

/**
 * Steps an institution takes before a module works, shown on the education
 * overview as one checklist ("what is left, and where to do it"). Education
 * gives its own; a module that needs Education (fees, exams, course
 * registration…) tags its provider "education.setup_steps" in its service
 * provider. A provider reads only its own module's records; its steps show
 * only while that module is on.
 */
interface SetupStepProvider
{
    /** The module these steps belong to (its key in the module registry). */
    public function module(): string;

    /**
     * The steps in order. A step: key, label (in the reader's language), done,
     * detail (what is missing, in words, when not done), route (the screen
     * where it is done), optional (helps but is not needed to start).
     *
     * @param  string|null  $sessionId  the session the institution works in now (the latest open one), if any
     * @param  list<string>  $unitIds  the unit asked about and those below it
     * @return list<array{key: string, label: string, done: bool, detail: ?string, route: string, optional?: bool}>
     */
    public function steps(Organization $company, ?string $sessionId, array $unitIds): array;
}
