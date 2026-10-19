<?php

namespace Modules\EduWebsite\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\EduWebsite\Services\BlockTypes;

/**
 * A block: its type (when adding) and its data (checked per type by
 * BlockTypes), the version seen when changing; or the order of a page's
 * blocks.
 */
class BlockRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if (str_ends_with($this->path(), '/order')) {
            return ['ids' => ['required', 'array', 'max:100'], 'ids.*' => ['string', 'size:26']];
        }
        $adding = $this->isMethod('post');

        return [
            'base_version' => [$adding ? 'prohibited' : 'required', 'integer', 'min:1'],
            'type' => [$adding ? 'required' : 'prohibited', 'string', 'in:'.implode(',', BlockTypes::TYPES)],
            'data' => ['present', 'array'],
        ];
    }

    /** The block's data as sent (BlockTypes keeps only what the type allows). */
    public function blockData(): array
    {
        return (array) $this->input('data', []);
    }
}
