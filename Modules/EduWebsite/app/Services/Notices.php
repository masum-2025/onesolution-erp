<?php

namespace Modules\EduWebsite\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Modules\EduWebsite\Exceptions\WebsiteException;
use Modules\EduWebsite\Models\Media;
use Modules\EduWebsite\Models\Notice;
use Modules\EduWebsite\Models\Site;

/**
 * The website's notice board. A notice is plain text per language with an
 * optional PDF of the site; it shows once published (pinned ones first,
 * then newest). Unlike pages, a notice goes live at once: the public pages
 * are built afresh. Publishing and removing are audited.
 */
class Notices
{
    public function __construct(private Place $place, private Sites $sites, private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  title, body, media_id, published_on, pinned, is_published; base_version when changing
     */
    public function save(Organization $company, Site $site, ?Notice $notice, array $data, User $actor): Notice
    {
        if (! empty($data['media_id']) && ! $this->place->query(Media::class, $company)->where('site_id', $site->getKey())->where('kind', 'pdf')->whereKey($data['media_id'])->exists()) {
            throw WebsiteException::mediaNotFound();
        }

        return $this->place->transaction($company, function () use ($company, $site, $notice, $data, $actor) {
            if ($notice !== null) {
                /** @var Notice $notice */
                $notice = $this->place->query(Notice::class, $company)->whereKey($notice->getKey())->lockForUpdate()->firstOrFail();
                if ($notice->version !== (int) ($data['base_version'] ?? 0)) {
                    throw WebsiteException::versionConflict(['version' => $notice->version]);
                }
            }
            $wasPublished = (bool) $notice?->is_published;
            $notice ??= new Notice(['organization_id' => $company->getKey(), 'site_id' => $site->getKey(), 'version' => 0]);
            $notice->fill(array_intersect_key($data, array_flip(['media_id', 'published_on', 'pinned', 'is_published'])));
            foreach (['title', 'body'] as $field) {
                if (array_key_exists($field, $data)) {
                    $notice->putTexts($field, $data[$field] ?? []);
                }
            }
            $notice->forceFill(['version' => $notice->version + 1])->save();
            if ($notice->is_published && ! $wasPublished) {
                $this->audit->record('edu_website.notice_published', $notice, new: ['title' => $notice->texts('title')], actor: $actor, organizationId: $company->getKey());
            }
            $this->sites->touch($company, $site->getKey());

            return $notice;
        });
    }

    public function remove(Organization $company, Notice $notice, int $baseVersion, User $actor): void
    {
        $this->place->transaction($company, function () use ($company, $notice, $baseVersion, $actor) {
            /** @var Notice $fresh */
            $fresh = $this->place->query(Notice::class, $company)->whereKey($notice->getKey())->lockForUpdate()->firstOrFail();
            if ($fresh->version !== $baseVersion) {
                throw WebsiteException::versionConflict(['version' => $fresh->version]);
            }
            $this->audit->record('edu_website.notice_removed', $fresh, old: ['title' => $fresh->texts('title')], actor: $actor, organizationId: $company->getKey());
            $fresh->delete();
            $this->sites->touch($company, $fresh->site_id);
        });
    }
}
