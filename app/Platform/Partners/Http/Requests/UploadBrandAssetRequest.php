<?php

namespace App\Platform\Partners\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

class UploadBrandAssetRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Raster images only: SVG can carry scripts.
            'file' => [
                'required', 'file', 'mimetypes:image/png,image/webp,image/jpeg',
                'max:'.(int) config('branding.asset_max_kb'),
                'dimensions:min_width=16,min_height=16,max_width=2048,max_height=2048',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimetypes' => __('partners.validation.image_type'),
            'file.max' => __('partners.validation.image_size', ['max' => (int) config('branding.asset_max_kb')]),
            'file.dimensions' => __('partners.validation.image_dimensions'),
        ];
    }
}
