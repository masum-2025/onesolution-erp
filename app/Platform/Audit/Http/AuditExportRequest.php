<?php

namespace App\Platform\Audit\Http;

class AuditExportRequest extends AuditFilterRequest
{
    protected bool $needsPeriod = true;

    protected function prepareForValidation(): void
    {
        $this->maxDays = (int) config('audit.export_max_days');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // An export is a period, optionally narrowed; paging does not apply.
        return array_diff_key(parent::rules(), array_flip(['page', 'per_page', 'filter']));
    }
}
