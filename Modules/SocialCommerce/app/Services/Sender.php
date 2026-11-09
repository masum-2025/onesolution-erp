<?php

namespace Modules\SocialCommerce\Services;

use App\Platform\Meta\Graph;
use App\Platform\Meta\MetaApps;
use App\Platform\Meta\Models\MetaApp;
use Modules\SocialCommerce\Exceptions\SocialCommerceException;
use Modules\SocialCommerce\Models\SocialPage;

/**
 * Answers going out on Facebook for a page: under a comment (public), to
 * the commenter's inbox (a private reply to that comment), or in a
 * Messenger chat (an answer within Meta's 24-hour window). Returns
 * Facebook's id of what was sent; Meta's refusals come as MetaException.
 */
class Sender
{
    public function __construct(private MetaApps $apps, private Graph $graph) {}

    public function underComment(SocialPage $page, string $commentId, string $text): string
    {
        return (string) ($this->graph->post($this->app($page), "{$commentId}/comments", (string) $page->token, ['message' => $text])['id'] ?? '');
    }

    public function privateReply(SocialPage $page, string $commentId, string $text): string
    {
        return $this->messages($page, ['recipient' => json_encode(['comment_id' => $commentId]), 'message' => json_encode(['text' => $text])]);
    }

    /** An answer in Messenger; with a tag (POST_PURCHASE_UPDATE), news sent past Meta's 24-hour window. */
    public function inMessenger(SocialPage $page, string $customerRef, string $text, ?string $tag = null): string
    {
        return $this->messages($page, [
            'recipient' => json_encode(['id' => $customerRef]),
            'messaging_type' => $tag === null ? 'RESPONSE' : 'MESSAGE_TAG',
            ...($tag === null ? [] : ['tag' => $tag]),
            'message' => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @param  array<string, string>  $body
     */
    private function messages(SocialPage $page, array $body): string
    {
        $sent = $this->graph->post($this->app($page), "{$page->page_id}/messages", (string) $page->token, $body);

        return (string) ($sent['message_id'] ?? $sent['id'] ?? '');
    }

    private function app(SocialPage $page): MetaApp
    {
        $app = $this->apps->find($page->meta_app_id);
        if ($app === null || ! $app->isActive() || $page->token === null || $page->status !== SocialPage::CONNECTED) {
            throw SocialCommerceException::pageUnusable($page->name);
        }

        return $app;
    }
}
