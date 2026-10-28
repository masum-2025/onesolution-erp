<?php

namespace App\Platform\Connections\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * An invitation code, looked up (preview) or used (accept, with the
 * companies and branches the provider may deal with).
 */
class CodeRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $accepting = str_ends_with((string) $this->path(), '/accept');

        return [
            'code' => ['required', 'string', 'max:30'],
            'unit_ids' => [$accepting ? 'required' : 'prohibited', 'array', 'min:1', 'max:200'],
            'unit_ids.*' => ['string', 'size:26'],
        ];
    }
}
