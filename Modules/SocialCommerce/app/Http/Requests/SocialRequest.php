<?php

namespace Modules\SocialCommerce\Http\Requests;

use App\Platform\Localization\LanguageRegistry;
use App\Platform\Support\Http\StrictFormRequest;
use Illuminate\Validation\Rule;

/** Facebook selling input: the rules depend on the route (see each named route in routes/api.php). */
class SocialRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $version = ['base_version' => ['required', 'integer', 'min:1']];
        $post = [
            'message' => ['nullable', 'string', 'max:5000'],
            'locale' => [Rule::in(LanguageRegistry::codes())],
            'publish_at' => ['nullable', 'date', 'before:+90 days'],
            'item_ids' => ['nullable', 'array', 'max:20'],
            'item_ids.*' => ['string', 'size:26'],
        ];

        $locales = LanguageRegistry::codes();

        return match (true) {
            $this->routeIs('social.pages.switches') => [
                ...$version, 'auto_reply' => ['sometimes', 'boolean'], 'price_public' => ['sometimes', 'boolean'],
                'order_branch_id' => ['sometimes', 'nullable', 'string', 'size:26'], 'order_warehouse_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            ],
            $this->routeIs('social.inbox.reply') => ['text' => ['required', 'string', 'min:1', 'max:2000'], 'op_id' => ['nullable', 'string', 'max:64']],
            $this->routeIs('social.inbox.mark') => $version,
            $this->routeIs('social.replies.try') => ['text' => ['required', 'string', 'max:1000'], 'post_id' => ['nullable', 'string', 'size:26']],
            $this->routeIs('social.replies.update') => [
                ...$version,
                'active' => ['required', 'boolean'],
                'keywords' => ['present', 'array:'.implode(',', $locales)],
                'keywords.*' => ['array', 'max:60'],
                'keywords.*.*' => ['string', 'max:60'],
                'answers' => ['required', 'array:'.implode(',', $locales)],
                'answers.*' => ['nullable', 'string', 'max:1000'],
            ],
            $this->routeIs('social.connect.pages') => ['state' => ['required', 'string', 'size:48'], 'page_ids' => ['required', 'array', 'min:1', 'max:50'], 'page_ids.*' => ['string', 'regex:/^\d{1,40}$/']],
            $this->routeIs('social.pages.disconnect', 'social.posts.submit', 'social.posts.approve', 'social.posts.withdraw', 'social.posts.destroy', 'social.media.destroy') => $version,
            $this->routeIs('social.posts.reject') => [...$version, 'reason' => ['required', 'string', 'min:3', 'max:300']],
            $this->routeIs('social.media.store') => [...$version, 'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:8192', 'dimensions:min_width=200,min_height=200,max_width=8000,max_height=8000']],
            $this->routeIs('social.media.order') => [...$version, 'media_ids' => ['required', 'array', 'max:10'], 'media_ids.*' => ['string', 'size:26']],
            $this->routeIs('social.posts.update') => [...$version, 'page_id' => ['sometimes', 'string', 'size:26'], ...array_map(fn (array $rules) => ['sometimes', ...$rules], $post)],
            default => [
                'page_id' => ['required', 'string', 'size:26'],
                ...$post,
                'locale' => ['required', ...$post['locale']],
                'op_id' => ['nullable', 'string', 'max:64'],
            ],
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimetypes' => __('social_commerce::social.validation.image_type'),
            'file.max' => __('social_commerce::social.validation.image_size'),
            'file.dimensions' => __('social_commerce::social.validation.image_dimensions'),
            'publish_at.before' => __('social_commerce::social.validation.too_far'),
            'reason.required' => __('social_commerce::social.validation.reason'),
        ];
    }
}
