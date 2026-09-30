<?php

namespace App\Platform\Audit\Http;

class AuditReportRequest extends AuditFilterRequest
{
    protected function prepareForValidation(): void
    {
        $this->maxDays = (int) config('audit.report_max_days');
    }
}
