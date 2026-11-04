<?php

namespace Modules\Letters\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Letters\Models\LetterAsset;
use Modules\Letters\Services\Templates;

/** An image added (kind, name, the file; its type is checked by Templates) or renamed / switched off. */
class AssetRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->isMethod('post')) {
            return [
                'kind' => ['required', 'in:'.implode(',', LetterAsset::KINDS)],
                'name' => ['required', 'string', 'min:1', 'max:80'],
                'file' => ['required', 'file', 'max:'.Templates::ASSET_MAX_KB],
            ];
        }

        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:80'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
