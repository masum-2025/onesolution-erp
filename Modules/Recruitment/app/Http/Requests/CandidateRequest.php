<?php

namespace Modules\Recruitment\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Recruitment\Models\Application;

/** Someone HR adds to an opening by hand: their name and contacts, how they came, a note; the same op id is the same application. */
class CandidateRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'opening_id' => ['required', 'string', 'size:26'],
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{5,30}$/'],
            'cover_note' => ['nullable', 'string', 'max:3000'],
            'source' => ['required', 'in:'.implode(',', array_diff(Application::SOURCES, ['careers']))],
            'op_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
