<?php

namespace Modules\SocialCommerce\Jobs;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\SocialCommerce\Models\SocialPage;
use Modules\SocialCommerce\Services\Conversations;
use Modules\SocialCommerce\Services\SocialBook;

/**
 * One webhook delivery from Meta for an app: each entry is a page; the page
 * (connected through this app, in whichever client database) says whose it
 * is. Comments (feed, item comment, verb add) and Messenger messages go to
 * Conversations; everything else is ignored. A page of nobody, or of a
 * company with Facebook selling off, is passed over.
 */
class HandleWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public readonly string $metaAppId, public readonly array $payload) {}

    public function handle(SocialBook $book, Conversations $conversations, ModuleResolver $modules): void
    {
        foreach ((array) ($this->payload['entry'] ?? []) as $entry) {
            $pageId = (string) ($entry['id'] ?? '');
            if ($pageId === '' || ($page = $this->page($book, $pageId)) === null) {
                continue;
            }
            $company = Organization::query()->find($page->organization_id);
            if ($company === null || ! $modules->isEnabled('social_commerce', $company)) {
                continue;
            }
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                if (($change['field'] ?? '') === 'feed' && ($value['item'] ?? '') === 'comment' && ($value['verb'] ?? '') === 'add') {
                    $conversations->commentReceived($company, $page, $value);
                }
            }
            foreach ((array) ($entry['messaging'] ?? []) as $event) {
                if (is_array($event) && isset($event['message'])) {
                    $conversations->messageReceived($company, $page, $event);
                }
            }
        }
    }

    private function page(SocialBook $book, string $pageId): ?SocialPage
    {
        $found = null;
        $book->everyDatabase(function () use ($pageId, &$found) {
            $found ??= SocialPage::query()->withoutGlobalScope(OrganizationScope::class)
                ->where('page_id', $pageId)->where('meta_app_id', $this->metaAppId)->where('status', SocialPage::CONNECTED)->first();
        });

        return $found === null ? null : $book->query(SocialPage::class, Organization::query()->findOrFail($found->organization_id))->find($found->getKey());
    }
}
