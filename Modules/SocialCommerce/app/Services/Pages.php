<?php

namespace Modules\SocialCommerce\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Meta\Exceptions\MetaException;
use App\Platform\Meta\Graph;
use App\Platform\Meta\MetaApps;
use App\Platform\Meta\Models\MetaApp;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Notifications\Services\Recipients;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Modules\SocialCommerce\Events\PageConnected;
use Modules\SocialCommerce\Exceptions\SocialCommerceException;
use Modules\SocialCommerce\Models\Post;
use Modules\SocialCommerce\Models\SocialPage;

/**
 * A company's Facebook pages (public service of the module).
 *
 * Connecting is three steps and no token ever reaches the browser:
 * start() gives the address of Meta's sign-in (a one-time state, bound to
 * the person and the company, kept 10 minutes); Meta sends the person back
 * to callback(), which swaps the code for page tokens and keeps them,
 * encrypted, under that state; the screen then shows candidates() and
 * save() keeps the pages chosen. A page belongs to one company only.
 */
class Pages
{
    /** What SC-1 asks Facebook for: list the pages, read them, post on them. */
    public const SCOPES = ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'];

    public function __construct(
        private SocialBook $book,
        private MetaApps $apps,
        private Graph $graph,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private Recipients $recipients,
        private Notifier $notifier,
        private AuditLogger $audit,
    ) {}

    /** The address of Meta's sign-in for this company. */
    public function start(Organization $company, User $actor, string $redirectUri): string
    {
        $app = $this->appFor($company);
        $state = Str::random(48);
        $this->remember($state, ['user' => $actor->getKey(), 'company' => $company->getKey(), 'app' => $app->getKey(), 'redirect' => $redirectUri, 'pages' => null]);

        return $this->graph->dialogUrl($app, $redirectUri, $state, self::SCOPES);
    }

    /**
     * Meta sent the person back: the code becomes page tokens, kept under the state.
     * Returns where the screen should go next (relative to the app).
     */
    public function callback(string $state, ?string $code, ?string $error): string
    {
        $held = $this->recall($state);
        if ($held === null) {
            return '/social/pages?connect=expired';
        }
        if ($error !== null || $code === null || $code === '') {
            Cache::forget($this->key($state));

            return '/social/pages?connect=cancelled';
        }
        $app = $this->apps->find((string) $held['app']);
        if ($app === null || ! $app->isActive()) {
            return '/social/pages?connect=expired';
        }

        try {
            $token = $this->graph->personToken($app, $code, (string) $held['redirect']);
            $person = (string) ($this->graph->get($app, 'me', $token, ['fields' => 'id'])['id'] ?? '');
            $found = $this->graph->get($app, 'me/accounts', $token, ['fields' => 'id,name,access_token,tasks,picture{url}', 'limit' => 100]);
        } catch (MetaException $e) {
            Cache::forget($this->key($state));

            return '/social/pages?connect=failed&reason='.$e->errorCode();
        }

        $pages = [];
        foreach ((array) ($found['data'] ?? []) as $page) {
            if (! is_array($page) || ! isset($page['id'], $page['access_token'])) {
                continue;
            }
            $pages[] = [
                'page_id' => (string) $page['id'],
                'name' => mb_substr((string) ($page['name'] ?? $page['id']), 0, 150),
                'picture_url' => mb_substr((string) ($page['picture']['data']['url'] ?? ''), 0, 1000) ?: null,
                'token' => (string) $page['access_token'],
                'can_post' => in_array('CREATE_CONTENT', (array) ($page['tasks'] ?? []), true),
            ];
        }
        $this->remember($state, [...$held, 'person' => $person, 'pages' => $pages]);

        return '/social/connect?state='.$state;
    }

    /**
     * The pages the person may connect (no tokens): whether each can post, is here already, or belongs to another company.
     *
     * @return list<array{page_id: string, name: string, picture_url: ?string, can_post: bool, connected_here: bool, taken: bool}>
     */
    public function candidates(Organization $company, User $actor, string $state): array
    {
        $held = $this->held($company, $actor, $state);
        $ids = array_column($held['pages'], 'page_id');
        $here = $this->book->query(SocialPage::class, $company)->whereIn('page_id', $ids)->where('status', SocialPage::CONNECTED)->pluck('page_id')->all();
        $taken = $this->takenElsewhere($company, $ids);

        return array_map(fn (array $page) => [
            'page_id' => $page['page_id'],
            'name' => $page['name'],
            'picture_url' => $page['picture_url'],
            'can_post' => $page['can_post'],
            'connected_here' => in_array($page['page_id'], $here, true),
            'taken' => in_array($page['page_id'], $taken, true),
        ], $held['pages']);
    }

    /**
     * Keeps the pages chosen (connecting a page again renews its token).
     *
     * @param  list<string>  $pageIds
     * @return list<SocialPage>
     */
    public function save(Organization $company, User $actor, string $state, array $pageIds): array
    {
        $held = $this->held($company, $actor, $state);
        $chosen = array_values(array_filter($held['pages'], fn (array $page) => in_array($page['page_id'], $pageIds, true)));
        if ($chosen === []) {
            throw SocialCommerceException::noPagesChosen();
        }
        $taken = $this->takenElsewhere($company, array_column($chosen, 'page_id'));
        foreach ($chosen as $page) {
            if (in_array($page['page_id'], $taken, true)) {
                throw SocialCommerceException::pageTaken($page['name']);
            }
        }

        $saved = $this->book->transaction($company, function () use ($company, $actor, $held, $chosen) {
            $saved = [];
            foreach ($chosen as $page) {
                $row = $this->book->query(SocialPage::class, $company)->where('page_id', $page['page_id'])->lockForUpdate()->first();
                $before = $row?->status;
                $row ??= $this->book->query(SocialPage::class, $company)->make(['organization_id' => $company->getKey(), 'page_id' => $page['page_id'], 'version' => 0]);
                $row->fill([
                    'meta_app_id' => $held['app'],
                    'name' => $page['name'],
                    'picture_url' => $page['picture_url'],
                    'token' => $page['token'],
                    'meta_person_id' => $held['person'] !== '' ? $held['person'] : null,
                    'can_post' => $page['can_post'],
                    'status' => SocialPage::CONNECTED,
                    'status_reason' => null,
                    'connected_by' => $actor->getKey(),
                    'connected_at' => CarbonImmutable::now(),
                    'version' => $row->version + 1,
                ])->save();
                $this->audit->record(
                    action: $before === null ? 'social_commerce.page_connected' : 'social_commerce.page_reconnected',
                    target: $row,
                    new: ['page' => $row->name, 'can_post' => $row->can_post],
                    actor: $actor,
                    organizationId: $company->getKey(),
                );
                $saved[] = $row;
            }

            return $saved;
        });
        Cache::forget($this->key($state));
        foreach ($saved as $row) {
            PageConnected::dispatch($company->getKey(), $row->getKey());
        }

        return $saved;
    }

    /** Stops using a page: the token is forgotten; posts waiting for it go back to drafts. */
    public function disconnect(Organization $company, SocialPage $page, int $baseVersion, User $actor): SocialPage
    {
        return $this->book->transaction($company, function () use ($company, $page, $baseVersion, $actor) {
            $page = $this->book->query(SocialPage::class, $company)->whereKey($page->getKey())->lockForUpdate()->firstOrFail();
            if ($page->version !== $baseVersion) {
                throw SocialCommerceException::versionConflict();
            }
            $page->fill(['token' => null, 'status' => SocialPage::DISCONNECTED, 'status_reason' => 'disconnected', 'version' => $page->version + 1])->save();
            $back = $this->toDrafts($company, $page);
            $this->audit->record(action: 'social_commerce.page_disconnected', target: $page, new: ['page' => $page->name, 'posts_back_to_draft' => $back], actor: $actor, organizationId: $company->getKey());

            return $page;
        });
    }

    /** Facebook stopped taking this page's token: someone must sign in again (told by mail). */
    public function needsReconnect(Organization $company, SocialPage $page, string $reason): void
    {
        $changed = $this->book->query(SocialPage::class, $company)->whereKey($page->getKey())->where('status', SocialPage::CONNECTED)
            ->update(['status' => SocialPage::NEEDS_RECONNECT, 'status_reason' => $reason, 'version' => $page->version + 1, 'updated_at' => now()]);
        if ($changed === 0) {
            return;
        }
        $this->audit->record(action: 'social_commerce.page_needs_reconnect', target: $page, new: ['page' => $page->name, 'reason' => $reason], organizationId: $company->getKey());
        $this->notifier->notify('social_commerce.page_needs_reconnect', $this->recipients->holding('social_commerce.connect', $company), fn (string $locale) => [
            'organization' => $company->displayName($locale),
            'page' => $page->name,
        ], $company->partner, $company);
    }

    /**
     * Pages of a Meta app (or one person in it) across every client database, for Meta's callbacks.
     * With forget, tokens are dropped and pages disconnected; else they wait for a new sign-in.
     */
    public function stopForApp(string $metaAppId, ?string $metaPersonId, bool $forget): int
    {
        $count = 0;
        $this->book->everyDatabase(function () use ($metaAppId, $metaPersonId, $forget, &$count) {
            $query = SocialPage::query()->withoutGlobalScope(OrganizationScope::class)->where('meta_app_id', $metaAppId)->where('status', '!=', SocialPage::DISCONNECTED);
            if ($metaPersonId !== null) {
                $query->where('meta_person_id', $metaPersonId);
            }
            foreach ($query->get() as $page) {
                $company = Organization::query()->find($page->organization_id);
                if ($company === null) {
                    continue;
                }
                if ($forget) {
                    $page->fill(['token' => null, 'meta_person_id' => null, 'status' => SocialPage::DISCONNECTED, 'status_reason' => 'data_deleted', 'version' => $page->version + 1])->save();
                    $this->toDrafts($company, $page);
                } else {
                    $this->needsReconnect($company, $page, $metaPersonId === null ? 'app_changed' : 'app_removed');
                }
                $count++;
            }
        });

        return $count;
    }

    /** The Meta app this company connects through; an error naming who to ask when there is none. */
    public function appFor(Organization $company): MetaApp
    {
        $houseAllowed = $this->rules->get('social_commerce.house_app_allowed', $this->contexts->forOrganization($company)) === true;

        return $this->apps->appFor($company->partner, $houseAllowed) ?? throw MetaException::noApp();
    }

    // ---------------------------------------------------------------- inside

    /**
     * @return array{user: string, company: string, app: string, redirect: string, person: string, pages: list<array<string, mixed>>}
     */
    private function held(Organization $company, User $actor, string $state): array
    {
        $held = $this->recall($state);
        if ($held === null || $held['pages'] === null || $held['user'] !== $actor->getKey() || $held['company'] !== $company->getKey()) {
            throw SocialCommerceException::connectExpired();
        }

        return $held;
    }

    /**
     * Page ids connected by another company, in any client database.
     *
     * @param  list<string>  $pageIds
     * @return list<string>
     */
    private function takenElsewhere(Organization $company, array $pageIds): array
    {
        $taken = [];
        $this->book->everyDatabase(function () use ($company, $pageIds, &$taken) {
            $taken = [...$taken, ...SocialPage::query()->withoutGlobalScope(OrganizationScope::class)
                ->whereIn('page_id', $pageIds)->where('organization_id', '!=', $company->getKey())
                ->where('status', '!=', SocialPage::DISCONNECTED)->pluck('page_id')->all()];
        });

        return array_values(array_unique($taken));
    }

    /** Posts waiting to go out on the page go back to drafts. Returns how many. */
    private function toDrafts(Organization $company, SocialPage $page): int
    {
        return $this->book->query(Post::class, $company)->where('page_id', $page->getKey())
            ->whereIn('status', [Post::PENDING, Post::SCHEDULED])
            ->update(['status' => Post::DRAFT, 'error_code' => 'page_disconnected', 'updated_at' => now()]);
    }

    private function key(string $state): string
    {
        return 'social:connect:'.hash('sha256', $state);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function remember(string $state, array $data): void
    {
        Cache::put($this->key($state), Crypt::encryptString((string) json_encode($data)), now()->addMinutes((int) config('meta.connect_minutes')));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function recall(string $state): ?array
    {
        $value = Cache::get($this->key($state));
        if (! is_string($value)) {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($value), true);
        } catch (\Throwable) {
            return null;
        }

        return is_array($data) ? $data : null;
    }
}
