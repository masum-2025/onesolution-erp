<?php

namespace Modules\EduWebsite\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/** The website's own domain (a DNS name; checked further by the Domains service). */
class DomainRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['host' => ['required', 'string', 'min:4', 'max:253']];
    }
}
