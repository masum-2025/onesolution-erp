<?php

namespace Modules\EduWebsite\Export;

use App\Platform\DataExport\Contracts\ExportsModuleData;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Modules\EduWebsite\Models\Block;
use Modules\EduWebsite\Models\Enquiry;
use Modules\EduWebsite\Models\Media;
use Modules\EduWebsite\Models\Notice;
use Modules\EduWebsite\Models\Page;
use Modules\EduWebsite\Models\Site;

/**
 * The website in the client's data export: site settings, draft pages and
 * their blocks, notices, the list of pictures and files (names and kinds;
 * the files themselves stay on the server), and the admission enquiries
 * still kept (the client's own record of the people who asked).
 */
class WebsiteExporter implements ExportsModuleData
{
    public function moduleKey(): string
    {
        return 'edu_website';
    }

    public function export(Organization $organization, array $organizationIds): array
    {
        $json = fn ($value) => json_encode($value, JSON_UNESCAPED_UNICODE);

        return [
            'sites' => $this->rows(Site::class, $organization, $organizationIds, fn (Site $site) => [
                'id' => $site->getKey(), 'slug' => $site->slug, 'template' => $site->template, 'title' => $json($site->texts('title')),
                'status' => $site->status, 'published_at' => $site->published_at?->toIso8601String(), 'contact' => $json($site->contact),
            ]),
            'pages' => $this->rows(Page::class, $organization, $organizationIds, fn (Page $page) => [
                'id' => $page->getKey(), 'site_id' => $page->site_id, 'slug' => $page->slug, 'title' => $json($page->texts('title')), 'is_home' => $page->is_home,
            ]),
            'blocks' => $this->rows(Block::class, $organization, $organizationIds, fn (Block $block) => [
                'page_id' => $block->page_id, 'type' => $block->type, 'sort_order' => $block->sort_order, 'data' => $json($block->data),
            ]),
            'notices' => $this->rows(Notice::class, $organization, $organizationIds, fn (Notice $notice) => [
                'id' => $notice->getKey(), 'title' => $json($notice->texts('title')), 'body' => $json($notice->texts('body')),
                'published_on' => $notice->published_on->toDateString(), 'is_published' => $notice->is_published, 'pinned' => $notice->pinned,
            ]),
            'enquiries' => $this->rows(Enquiry::class, $organization, $organizationIds, fn (Enquiry $enquiry) => [
                'id' => $enquiry->getKey(), 'created_at' => $enquiry->created_at->toIso8601String(), 'student_name' => $enquiry->student_name,
                'guardian_name' => $enquiry->guardian_name, 'phone' => $enquiry->phone, 'class' => $json($enquiry->level_name),
                'message' => $enquiry->message, 'status' => $enquiry->status, 'note' => $enquiry->note,
            ]),
            'media' => $this->rows(Media::class, $organization, $organizationIds, fn (Media $media) => [
                'id' => $media->getKey(), 'kind' => $media->kind, 'name' => $media->name, 'size_bytes' => $media->size_bytes,
            ]),
        ];
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  list<string>  $organizationIds
     * @return iterable<array<string, mixed>>
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
