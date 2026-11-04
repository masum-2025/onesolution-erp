<?php

namespace Modules\Letters\Http;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Modules\Letters\Models\Letter;
use Modules\Letters\Models\LetterAsset;
use Modules\Letters\Models\LetterTemplate;
use Modules\Letters\Services\Templates;

/** Letters' API shapes (JSON resources in one place). */
class LettersPresenter
{
    public function __construct(private Templates $templates) {}

    /**
     * A design in a list (no bodies).
     *
     * @return array<string, mixed>
     */
    public function template(LetterTemplate $template): array
    {
        return [
            'id' => $template->getKey(),
            'name' => $template->texts('name'),
            'kind' => $template->kind,
            'code' => $template->code,
            'preset' => $template->preset,
            'status' => $template->status,
            'uses_salary' => (bool) $template->uses_salary,
            'locales' => array_keys((array) $template->bodies),
            'has_inputs' => ! empty($template->inputs),
            'version' => $template->version,
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }

    /**
     * A design with everything to edit or issue it, and its images' links.
     *
     * @return array<string, mixed>
     */
    public function templateFull(Organization $company, LetterTemplate $template): array
    {
        return [
            ...$this->template($template),
            'page' => $template->page,
            'letterhead' => $template->letterhead,
            'inputs' => array_values((array) $template->inputs),
            'bodies' => $template->bodies,
            'images' => $this->templates->assetLinks($company, (array) $template->letterhead, (array) $template->bodies),
        ];
    }

    /**
     * Letters in a list (no content).
     *
     * @param  iterable<Letter>  $letters
     * @return list<array<string, mixed>>
     */
    public function letters(iterable $letters): array
    {
        $letters = Collection::make($letters);
        $names = User::query()->whereKey($letters->pluck('issued_by')->unique()->values()->all())->pluck('name', 'id');

        return $letters->map(fn (Letter $letter) => [
            'id' => $letter->getKey(),
            'number' => $letter->number,
            'title' => $letter->title,
            'kind' => $letter->kind,
            'locale' => $letter->locale,
            'employee' => ['id' => $letter->employee_id, 'code' => $letter->employee_code, 'name' => $letter->employee_name],
            'uses_salary' => (bool) $letter->uses_salary,
            'shared' => (bool) $letter->shared,
            'source' => $letter->source_type === null ? null : ['type' => $letter->source_type, 'id' => $letter->source_id],
            'issued_at' => $letter->issued_at->toIso8601String(),
            'issued_by' => $names[$letter->issued_by] ?? null,
            'revoked' => $letter->revoked_at !== null,
            'revoked_at' => $letter->revoked_at?->toIso8601String(),
            'revoke_reason' => $letter->revoke_reason,
        ])->values()->all();
    }

    /**
     * One letter as issued, with its images' links (to view and print).
     *
     * @param  array<string, bool>  $can
     * @return array<string, mixed>
     */
    public function letter(Organization $company, Letter $letter, array $can = []): array
    {
        $content = (array) $letter->content;

        return [
            ...$this->letters([$letter])[0],
            'content' => $content,
            'images' => $this->templates->assetLinks($company, (array) ($content['letterhead'] ?? []), ['body' => (array) ($content['blocks'] ?? [])]),
            'can' => $can,
        ];
    }

    /** @return array<string, mixed> */
    public function asset(LetterAsset $asset, string $link): array
    {
        return [
            'id' => $asset->getKey(),
            'kind' => $asset->kind,
            'name' => $asset->name,
            'is_active' => (bool) $asset->is_active,
            'size_bytes' => $asset->size_bytes,
            'url' => $link,
        ];
    }
}
