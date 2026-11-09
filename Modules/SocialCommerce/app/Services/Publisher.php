<?php

namespace Modules\SocialCommerce\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Meta\Exceptions\MetaException;
use App\Platform\Meta\Graph;
use App\Platform\Meta\MetaApps;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Modules\SocialCommerce\Events\PostFailed;
use Modules\SocialCommerce\Events\PostPublished;
use Modules\SocialCommerce\Models\Post;
use Modules\SocialCommerce\Models\PostMedia;
use Modules\SocialCommerce\Models\SocialPage;

/**
 * Puts one approved post on Facebook. The post is claimed first (scheduled
 * → publishing, at its version), so two workers never send it twice.
 * Photos are uploaded unpublished (each remembered, so a retry does not
 * upload it again) and attached to one feed post with the text and the
 * footer line. Facebook busy or out of reach: tried again later (up to
 * MAX_ATTEMPTS). Token gone or permission taken back: the page waits for a
 * new sign-in and the post fails. A failure tells the writer by mail.
 */
class Publisher
{
    public const MAX_ATTEMPTS = 3;

    /** Minutes to wait before trying again, times the attempt. */
    public const RETRY_MINUTES = 5;

    public function __construct(
        private SocialBook $book,
        private Posts $posts,
        private Pages $pages,
        private MetaApps $apps,
        private Graph $graph,
        private ModuleResolver $modules,
        private Notifier $notifier,
        private AuditLogger $audit,
    ) {}

    /** Returns where the post stands afterwards (or null when there was nothing to do). */
    public function publish(Organization $company, string $postId): ?string
    {
        $post = $this->book->query(Post::class, $company)->whereKey($postId)->first();
        if ($post === null || $post->status !== Post::SCHEDULED || ($post->publish_at !== null && $post->publish_at->isFuture())) {
            return null;
        }
        if (! $this->modules->isEnabled('social_commerce', $company)) {
            return null;
        }
        $claimed = $this->book->query(Post::class, $company)->whereKey($postId)->where('status', Post::SCHEDULED)->where('version', $post->version)
            ->update(['status' => Post::PUBLISHING, 'attempts' => $post->attempts + 1, 'version' => $post->version + 1, 'updated_at' => now()]);
        if ($claimed === 0) {
            return null;
        }
        $post->refresh();

        $page = $this->book->query(SocialPage::class, $company)->find($post->page_id);
        $app = $page === null ? null : $this->apps->find($page->meta_app_id);
        if ($page === null || ! $page->usable() || $app === null || ! $app->isActive()) {
            return $this->fail($company, $post, 'page_unusable', '');
        }

        try {
            $media = $this->book->query(PostMedia::class, $company)->where('post_id', $post->getKey())->orderBy('position')->get();
            $attached = [];
            foreach ($media as $photo) {
                if ($photo->fb_photo_id === null) {
                    $contents = Storage::disk('local')->get($photo->path);
                    if ($contents === null) {
                        return $this->fail($company, $post, 'photo_missing', '');
                    }
                    $uploaded = $this->graph->upload($app, "{$page->page_id}/photos", (string) $page->token, $contents, basename($photo->path), ['published' => 'false']);
                    $photo->fb_photo_id = (string) ($uploaded['id'] ?? '');
                    $photo->save();
                }
                $attached[] = json_encode(['media_fbid' => $photo->fb_photo_id]);
            }

            $body = ['message' => $this->message($company, $post)];
            foreach ($attached as $index => $value) {
                $body["attached_media[{$index}]"] = $value;
            }
            $created = $this->graph->post($app, "{$page->page_id}/feed", (string) $page->token, $body);
        } catch (MetaException $e) {
            if ($e->needsReconnect()) {
                $this->pages->needsReconnect($company, $page, $e->errorCode());

                return $this->fail($company, $post, $e->errorCode(), $e->metaMessage());
            }
            if (in_array($e->errorCode(), [MetaException::RATE_LIMITED, MetaException::UNREACHABLE], true) && $post->attempts < self::MAX_ATTEMPTS) {
                $this->book->query(Post::class, $company)->whereKey($post->getKey())->update([
                    'status' => Post::SCHEDULED,
                    'publish_at' => CarbonImmutable::now()->addMinutes(self::RETRY_MINUTES * $post->attempts),
                    'error_code' => $e->errorCode(),
                    'version' => $post->version + 1,
                    'updated_at' => now(),
                ]);

                return Post::SCHEDULED;
            }

            return $this->fail($company, $post, $e->errorCode(), $e->metaMessage());
        }

        $fbPostId = (string) ($created['id'] ?? '');
        $post->forceFill([
            'status' => Post::PUBLISHED,
            'fb_post_id' => $fbPostId !== '' ? $fbPostId : null,
            'published_at' => CarbonImmutable::now(),
            'error_code' => null,
            'error_message' => null,
            'version' => $post->version + 1,
        ])->save();
        $this->audit->record(action: 'social_commerce.post_published', target: $post, new: ['page' => $page->name, 'photos' => count($attached)], organizationId: $company->getKey());
        PostPublished::dispatch($company->getKey(), $post->getKey(), $fbPostId, array_column($this->posts->productsOf($company, $post->getKey()), 'item_id'));

        return Post::PUBLISHED;
    }

    /** The text that goes out: what was written, then the footer line in the post's language. */
    public function message(Organization $company, Post $post): string
    {
        return trim(implode("\n\n", array_filter([(string) $post->message, $this->posts->footer($company, $post->locale)], fn (string $part) => trim($part) !== '')));
    }

    private function fail(Organization $company, Post $post, string $code, string $metaMessage): string
    {
        $post->forceFill([
            'status' => Post::FAILED,
            'error_code' => mb_substr($code, 0, 40),
            'error_message' => $metaMessage !== '' ? mb_substr($metaMessage, 0, 300) : null,
            'version' => $post->version + 1,
        ])->save();
        $this->audit->record(action: 'social_commerce.post_failed', target: $post, new: ['code' => $code], organizationId: $company->getKey());
        PostFailed::dispatch($company->getKey(), $post->getKey(), $code);

        $author = $post->created_by === null ? null : User::query()->find($post->created_by);
        if ($author !== null) {
            $page = $this->book->query(SocialPage::class, $company)->find($post->page_id);
            $this->notifier->notify('social_commerce.post_failed', [$author], fn (string $locale) => [
                'organization' => $company->displayName($locale),
                'page' => (string) $page?->name,
                'reason' => __(trans()->has("social_commerce::social.failures.{$code}", $locale) ? "social_commerce::social.failures.{$code}" : 'social_commerce::social.failures.rejected', [], $locale),
            ], $company->partner, $company);
        }

        return Post::FAILED;
    }
}
