<?php

namespace App\Platform\Billing\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class PayInvoiceRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return SelfServePlanRequest::opRule();
    }
}
