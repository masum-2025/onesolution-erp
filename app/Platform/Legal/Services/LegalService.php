<?php

namespace App\Platform\Legal\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Legal\Events\LegalDocumentPublished;
use App\Platform\Legal\Exceptions\LegalException;
use App\Platform\Legal\Models\DocumentAcceptance;
use App\Platform\Legal\Models\LegalDocument;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Terms, privacy notice and data processing agreement. A client is bound by
 * its partner's latest published version, or the platform's when the partner
 * has none. Clients (their top organization's owners) accept terms and the
 * DPA; each acceptance is recorded once, with who and when.
 */
class LegalService
{
    public function __construct(private RuleResolver $rules, private RuleContextFactory $contexts, private AuditLogger $audit) {}

    public function current(?Partner $partner, string $kind): ?LegalDocument
    {
        $this->assertKind($kind);

        $latest = fn (?string $partnerId) => LegalDocument::query()
            ->where('partner_id', $partnerId)
            ->where('kind', $kind)
            ->orderByDesc('version')
            ->first();

        return ($partner === null ? null : $latest($partner->getKey())) ?? $latest(null);
    }

    /**
     * The documents a client still has to accept (empty when acceptance is
     * not required for it).
     *
     * @return list<LegalDocument>
     */
    public function pending(Organization $root): array
    {
        if (! (bool) $this->rules->get('legal.acceptance_required', $this->contexts->forOrganization($root))) {
            return [];
        }

        $pending = [];
        foreach (LegalDocument::ACCEPTED_KINDS as $kind) {
            $document = $this->current($root->partner, $kind);
            if ($document !== null && ! $this->accepted($root, $document)) {
                $pending[] = $document;
            }
        }

        return $pending;
    }

    public function accepted(Organization $root, LegalDocument $document): ?DocumentAcceptance
    {
        return DocumentAcceptance::query()
            ->where('organization_id', $root->getKey())
            ->where('legal_document_id', $document->getKey())
            ->first();
    }

    /**
     * Accept the version the person read. A newer one published meanwhile must be read first.
     */
    public function accept(Organization $root, string $kind, int $version, User $user, string $locale): DocumentAcceptance
    {
        $document = $this->current($root->partner, $kind) ?? throw LegalException::noDocument();
        if ($document->version !== $version) {
            throw LegalException::outdated($document->version);
        }

        $existing = $this->accepted($root, $document);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($root, $document, $user, $locale) {
                $acceptance = new DocumentAcceptance;
                $acceptance->forceFill([
                    'organization_id' => $root->getKey(),
                    'legal_document_id' => $document->getKey(),
                    'kind' => $document->kind,
                    'version' => $document->version,
                    'locale' => $locale,
                    'accepted_by' => $user->getKey(),
                    'accepted_at' => now(),
                ])->save();

                $this->audit->record(
                    action: 'legal.accepted',
                    target: $acceptance,
                    new: ['kind' => $document->kind, 'version' => $document->version, 'document' => $document->getKey(), 'locale' => $locale],
                    actor: $user,
                    organizationId: $root->getKey(),
                    partnerId: $root->partner_id,
                );

                return $acceptance;
            });
        } catch (UniqueConstraintViolationException) {
            // Two owners accepted at the same moment: once is enough.
            return $this->accepted($root, $document);
        }
    }

    /**
     * Publish a new version. Published versions never change.
     *
     * @param  array<string, string>  $title
     * @param  array<string, string>  $body
     */
    public function publish(?Partner $partner, string $kind, array $title, array $body, ?string $summary, ?User $actor): LegalDocument
    {
        $this->assertKind($kind);

        return DB::transaction(function () use ($partner, $kind, $title, $body, $summary, $actor) {
            $last = LegalDocument::query()
                ->where('partner_id', $partner?->getKey())
                ->where('kind', $kind)
                ->lockForUpdate()
                ->max('version');

            $document = new LegalDocument;
            $document->forceFill([
                'partner_id' => $partner?->getKey(),
                'kind' => $kind,
                'version' => (int) $last + 1,
                'title' => $this->texts($title),
                'body' => $this->texts($body),
                'summary' => $summary,
                'published_at' => now(),
                'published_by' => $actor?->getKey(),
            ])->save();

            $this->audit->record(
                action: 'legal.published',
                target: $document,
                new: ['kind' => $kind, 'version' => $document->version, 'summary' => $summary],
                actor: $actor,
                partnerId: $partner?->getKey(),
            );

            LegalDocumentPublished::dispatch($document);

            return $document;
        });
    }

    /**
     * Publish the data file's platform documents where none exists yet (a new
     * installation). After that the platform's versions are published from
     * the house partner's console, so a deploy never overwrites them; $force
     * publishes the file's text anyway where it differs from the latest.
     *
     * @param  array<string, array{title: array<string, string>, body: array<string, string>, summary?: string}>  $documents
     * @return int Versions published.
     */
    public function syncPlatform(array $documents, bool $force = false): int
    {
        $published = 0;
        foreach ($documents as $kind => $document) {
            $current = $this->current(null, $kind);
            if ($current !== null && (! $force || ($current->title == $this->texts($document['title']) && $current->body == $this->texts($document['body'])))) {
                continue;
            }

            $this->publish(null, $kind, $document['title'], $document['body'], $document['summary'] ?? null, null);
            $published++;
        }

        return $published;
    }

    private function assertKind(string $kind): void
    {
        if (! in_array($kind, LegalDocument::KINDS, true)) {
            throw LegalException::unknownKind();
        }
    }

    /**
     * @param  array<string, string|null>  $texts
     * @return array<string, string>
     */
    private function texts(array $texts): array
    {
        return array_filter(array_map(fn ($text) => $text === null ? null : trim(str_replace("\r\n", "\n", $text)), $texts), fn ($text) => $text !== null && $text !== '');
    }
}
