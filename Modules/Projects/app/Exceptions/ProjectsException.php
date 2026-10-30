<?php

namespace Modules\Projects\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Projects errors: a translated message (projects::projects.errors.*) that
 * says what to do next, and a stable code.
 */
class ProjectsException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'projects::projects.errors.'.$this->errorCode;
    }

    /** Projects run at a company or a branch that is open. */
    public static function notWorkplace(): self
    {
        return new self('not_workplace', 422, [], ['field' => 'unit_id']);
    }

    public static function projectNotFound(): self
    {
        return new self('project_not_found', 404);
    }

    public static function taskNotFound(): self
    {
        return new self('task_not_found', 404);
    }

    public static function templateNotFound(): self
    {
        return new self('template_not_found', 404, [], ['field' => 'template_id']);
    }

    public static function notTemplateOwner(): self
    {
        return new self('not_template_owner', 422);
    }

    public static function versionConflict(): self
    {
        return new self('version_conflict', 409);
    }

    public static function wrongStep(string $status): self
    {
        return new self('wrong_step', 409, ['status' => __("projects::projects.statuses.{$status}")]);
    }

    /** Tasks change only while the project is planned, active or on hold. */
    public static function projectClosed(): self
    {
        return new self('project_closed', 409);
    }

    public static function datesOrder(): self
    {
        return new self('dates_order', 422, [], ['field' => 'due_on']);
    }

    public static function clientNeeded(): self
    {
        return new self('client_needed', 422, [], ['field' => 'client_contact_id']);
    }

    public static function clientUnknown(): self
    {
        return new self('client_unknown', 422, [], ['field' => 'client_contact_id']);
    }

    /** Only people who may work on projects here can be given a task or lead a project. */
    public static function notAssignable(): self
    {
        return new self('not_assignable', 422, [], ['field' => 'user_ids']);
    }

    public static function tooManyAssignees(int $max): self
    {
        return new self('too_many_assignees', 422, ['max' => $max], ['field' => 'user_ids']);
    }

    public static function tooManyTasks(int $max): self
    {
        return new self('too_many_tasks', 422, ['max' => $max]);
    }

    /** Quotes need CRM on at the company. */
    public static function crmOff(): self
    {
        return new self('crm_off', 422);
    }

    /** Invoices need Accounting on and its books set up at the company. */
    public static function booksOff(): self
    {
        return new self('books_off', 422);
    }

    /** Quotes and invoices go to a client kept in CRM (add the client there first). */
    public static function clientNotInCrm(): self
    {
        return new self('client_not_in_crm', 422, [], ['field' => 'client_contact_id']);
    }

    /** Someone with projects.work changes only tasks they are on. */
    public static function notYourTask(): self
    {
        return new self('not_your_task', 403);
    }
}
