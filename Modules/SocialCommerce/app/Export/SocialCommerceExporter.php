<?php

namespace Modules\SocialCommerce\Export;

use App\Platform\DataExport\Contracts\ExportsModuleData;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
use Modules\SocialCommerce\Models\Post;
use Modules\SocialCommerce\Models\PostProduct;
use Modules\SocialCommerce\Models\SocialPage;

/** Facebook selling in the client's data export: pages (never their tokens), posts and the products they show. */
class SocialCommerceExporter implements ExportsModuleData
{
    public function moduleKey(): string
    {
        return 'social_commerce';
    }

    public function export(Organization $organization, array $organizationIds): array
    {
        return [
            'pages' => $this->rows(SocialPage::class, $organization, $organizationIds, fn (SocialPage $row) => [
                'id' => $row->getKey(), 'page_id' => $row->page_id, 'name' => $row->name, 'status' => $row->status, 'can_post' => $row->can_post,
                'connected_at' => $row->connected_at?->toIso8601String(),
            ]),
            'posts' => $this->rows(Post::class, $organization, $organizationIds, fn (Post $row) => [
                'id' => $row->getKey(), 'page_id' => $row->page_id, 'message' => $row->message, 'locale' => $row->locale, 'status' => $row->status,
                'publish_at' => $row->publish_at?->toIso8601String(), 'published_at' => $row->published_at?->toIso8601String(), 'fb_post_id' => $row->fb_post_id,
                'created_at' => $row->created_at?->toIso8601String(),
            ]),
            'post_products' => $this->rows(PostProduct::class, $organization, $organizationIds, fn (PostProduct $row) => [
                'post_id' => $row->post_id, 'item_id' => $row->item_id, 'price_minor' => $row->price_minor, 'currency_code' => $row->currency_code,
            ]),
        ];
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @param  list<string>  $organizationIds
     * @param  callable(T): array<string, scalar|null>  $row
     * @return iterable<array<string, scalar|null>>
     */
    private function rows(string $model, Organization $organization, array $organizationIds, callable $row): iterable
    {
        foreach (array_chunk($organizationIds, 500) as $chunk) {
            foreach ($model::inTenantOf($organization)->withoutGlobalScope(OrganizationScope::class)->whereIn('organization_id', $chunk)->orderBy('id')->lazy(500) as $record) {
                yield $row($record);
            }
        }
    }
}
