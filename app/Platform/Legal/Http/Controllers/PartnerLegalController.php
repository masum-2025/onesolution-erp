<?php

namespace App\Platform\Legal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Legal\Exceptions\LegalException;
use App\Platform\Legal\Http\Requests\PublishDocumentRequest;
use App\Platform\Legal\Models\LegalDocument;
use App\Platform\Legal\Services\LegalService;
use App\Platform\Partners\Http\Controllers\Concerns\PartnerConsole;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Partner console: the partner's own terms, privacy notice and data
 * processing agreement. Without its own version, clients get the platform's.
 * Publishing a new version asks every client to accept it again (terms, DPA).
 *
 * The house partner's owners (One Solutions staff) also publish the
 * platform's own versions: the default for every partner without its own.
 */
class PartnerLegalController extends Controller
{
    use PartnerConsole;

    public function __construct(private LegalService $legal) {}

    public function index(): JsonResponse
    {
        $partner = $this->partner();
        $platformEditor = $this->editsPlatform();

        return response()->json([
            'data' => array_map(function (string $kind) use ($partner, $platformEditor) {
                $current = $this->legal->current($partner, $kind);
                $own = LegalDocument::query()->where('partner_id', $partner->getKey())->where('kind', $kind)->orderByDesc('version')->get();
                $platform = $this->legal->current(null, $kind);

                return [
                    'kind' => $kind,
                    'needs_acceptance' => in_array($kind, LegalDocument::ACCEPTED_KINDS, true),
                    'current' => $current === null ? null : $this->present($current),
                    'own_versions' => $own->map(fn (LegalDocument $document) => $this->present($document))->values(),
                    // Only for those who can change it.
                    'platform' => $platformEditor && $platform !== null ? $this->present($platform) : null,
                ];
            }, LegalDocument::KINDS),
            'can_publish' => $this->hasRole(PartnerUserRole::Owner),
            'can_publish_platform' => $platformEditor,
        ]);
    }

    public function show(Request $request, string $kind, int $version): JsonResponse
    {
        $platform = $request->query('scope') === 'platform';
        if ($platform && ! $this->editsPlatform()) {
            throw LegalException::roleNotAllowed();
        }

        $document = match (true) {
            $platform => $this->legal->current(null, $kind),
            $version === 0 => $this->legal->current($this->partner(), $kind),
            default => LegalDocument::query()->where('partner_id', $this->partner()->getKey())->where('kind', $kind)->where('version', $version)->first(),
        };

        if ($document === null) {
            throw LegalException::noDocument();
        }

        return response()->json(['data' => [...$this->present($document), 'title_texts' => $document->title, 'body_texts' => $document->body]]);
    }

    public function publish(PublishDocumentRequest $request, string $kind): JsonResponse
    {
        $platform = $request->validated('scope') === 'platform';

        if (! $this->hasRole(PartnerUserRole::Owner) || ($platform && ! $this->editsPlatform())) {
            throw LegalException::roleNotAllowed();
        }

        $document = $this->legal->publish($platform ? null : $this->partner(), $kind, $request->validated('title'), $request->validated('body'), $request->validated('summary'), $request->user());

        return response()->json([
            'data' => $this->present($document),
            'message' => __($platform ? 'legal.messages.published_platform' : 'legal.messages.published', ['version' => $document->version]),
        ], 201);
    }

    /** The platform's defaults are One Solutions' own: the house partner's owners. */
    private function editsPlatform(): bool
    {
        return (bool) $this->partner()->is_house && $this->hasRole(PartnerUserRole::Owner);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(LegalDocument $document): array
    {
        return [
            'kind' => $document->kind,
            'version' => $document->version,
            'title' => $document->text('title'),
            'summary' => $document->summary,
            'published_at' => $document->published_at->toIso8601String(),
            'platform' => $document->partner_id === null,
        ];
    }
}
