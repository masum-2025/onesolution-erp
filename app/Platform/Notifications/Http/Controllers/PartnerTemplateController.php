<?php

namespace App\Platform\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Branding\BrandResolver;
use App\Platform\Notifications\Exceptions\NotificationException;
use App\Platform\Notifications\Http\Requests\TemplateRequest;
use App\Platform\Notifications\Models\NotificationTemplate;
use App\Platform\Notifications\NotificationCatalog;
use App\Platform\Notifications\Services\LinkBuilder;
use App\Platform\Notifications\Services\MailSender;
use App\Platform\Notifications\Services\SmsText;
use App\Platform\Notifications\Services\TemplateRenderer;
use App\Platform\Notifications\Services\TemplateService;
use App\Platform\Partners\Http\Controllers\Concerns\PartnerConsole;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Partner console: the wording of every message its clients receive, per
 * channel and language. Unchanged messages use the platform default.
 */
class PartnerTemplateController extends Controller
{
    use PartnerConsole;

    public function __construct(
        private NotificationCatalog $catalog,
        private TemplateRenderer $renderer,
        private TemplateService $templates,
        private MailSender $mail,
        private BrandResolver $brands,
        private LinkBuilder $links,
    ) {}

    public function index(): JsonResponse
    {
        $custom = NotificationTemplate::query()
            ->where('partner_id', $this->partner()->getKey())
            ->get(['notification_key', 'channel', 'locale'])
            ->groupBy('notification_key');

        return response()->json([
            'data' => array_map(fn (string $key) => [
                'key' => $key,
                'name' => __('notifications.catalog.'.NotificationCatalog::slug($key).'.name'),
                'description' => __('notifications.catalog.'.NotificationCatalog::slug($key).'.description'),
                'audience' => $this->catalog->get($key)['audience'],
                'channels' => $this->catalog->get($key)['channels'],
                'customized' => ($custom[$key] ?? collect())->map(fn ($row) => "{$row->channel}.{$row->locale}")->values(),
            ], $this->catalog->keys()),
            'locales' => config('tenancy.supported_locales'),
            'can_edit' => $this->hasRole(PartnerUserRole::Owner),
        ]);
    }

    public function show(string $notification): JsonResponse
    {
        $this->known($notification);
        $partner = $this->partner();
        $definition = $this->catalog->get($notification);

        $wording = [];
        foreach ($definition['channels'] as $channel) {
            foreach ((array) config('tenancy.supported_locales') as $locale) {
                $wording[$channel][$locale] = [
                    ...$this->renderer->wording($partner, $notification, $channel, $locale),
                    'default' => $this->renderer->default($notification, $channel, $locale),
                ];
            }
        }

        return response()->json(['data' => [
            'key' => $notification,
            'name' => __('notifications.catalog.'.NotificationCatalog::slug($notification).'.name'),
            'description' => __('notifications.catalog.'.NotificationCatalog::slug($notification).'.description'),
            'placeholders' => array_map(fn (string $name) => ['name' => $name, 'description' => __("notifications.placeholders.{$name}")], $definition['placeholders']),
            'wording' => $wording,
            'can_edit' => $this->hasRole(PartnerUserRole::Owner),
        ]]);
    }

    public function update(TemplateRequest $request, string $notification, string $channel, string $locale): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);
        $this->known($notification, $channel, $locale);

        $template = $this->templates->save($this->partner(), $notification, $channel, $locale, $request->validated('subject'), $request->validated('body'), $request->user());

        return response()->json(['data' => ['version' => $template->version], 'message' => __('notifications.messages.template_saved')]);
    }

    public function destroy(Request $request, string $notification, string $channel, string $locale): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);
        $this->known($notification, $channel, $locale);

        $this->templates->reset($this->partner(), $notification, $channel, $locale, $request->user());

        return response()->json(['data' => $this->renderer->default($notification, $channel, $locale), 'message' => __('notifications.messages.template_reset')]);
    }

    /**
     * How a (possibly unsaved) wording looks with example values, in the partner's brand.
     */
    public function preview(TemplateRequest $request, string $notification, string $channel, string $locale): JsonResponse
    {
        $this->known($notification, $channel, $locale);
        $partner = $this->partner();

        foreach (['subject', 'body'] as $field) {
            $unknown = $this->renderer->unknownPlaceholders($notification, (string) $request->validated($field));
            if ($unknown !== []) {
                throw NotificationException::unknownPlaceholders($unknown, $field);
            }
        }

        $definition = $this->catalog->get($notification);
        $values = $this->renderer->sample($notification, $locale, $this->brands->for($partner)['name'], $this->links->to($definition['path'], $partner));
        $body = (string) $this->renderer->fill($request->validated('body'), $values);

        if ($channel === 'sms') {
            return response()->json(['data' => ['text' => $body, 'length' => mb_strlen($body), 'segments' => SmsText::segments($body), 'unicode' => SmsText::isUnicode($body)]]);
        }

        $action = ['label' => __('notifications.templates.'.NotificationCatalog::slug($notification).'.action', [], $locale), 'url' => $values['link']];
        $rendered = $this->mail->render($partner, $body, $action, $locale);

        // The editor frames it from its own address, with its own policy (inline styles, no scripts).
        $id = Str::random(40);
        Cache::put("template-preview:{$id}", ['user' => $request->user()->getAuthIdentifier(), 'html' => $rendered['html']], now()->addMinutes(TemplatePreviewController::TTL_MINUTES));

        return response()->json(['data' => [
            'subject' => $this->renderer->fill($request->validated('subject'), $values),
            ...$rendered,
            'preview_url' => "/partner-preview/{$id}",
            'from' => $this->mail->sender($partner)['name'],
        ]]);
    }

    private function known(string $notification, ?string $channel = null, ?string $locale = null): void
    {
        if (! $this->catalog->has($notification)
            || ($channel !== null && ! $this->catalog->supports($notification, $channel))
            || ($locale !== null && ! in_array($locale, (array) config('tenancy.supported_locales'), true))) {
            throw NotificationException::unknownNotification();
        }
    }
}
