<?php

namespace Modules\Letters\Export;

use App\Platform\DataExport\Contracts\ExportsModuleData;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
use Modules\Letters\Models\Letter;
use Modules\Letters\Models\LetterAsset;
use Modules\Letters\Models\LetterTemplate;

/**
 * Letters in the client's data export: designs (whole, as JSON), images
 * (names, not files), and every letter issued with its content as issued.
 */
class LettersExporter implements ExportsModuleData
{
    public function moduleKey(): string
    {
        return 'letters';
    }

    public function export(Organization $organization, array $organizationIds): array
    {
        $json = fn (mixed $value) => json_encode($value, JSON_UNESCAPED_UNICODE);

        return [
            'templates' => $this->rows(LetterTemplate::class, $organization, $organizationIds, fn (LetterTemplate $template) => [
                'id' => $template->getKey(), 'kind' => $template->kind, 'code' => $template->code, 'name' => $json($template->texts('name')),
                'status' => $template->status, 'uses_salary' => $template->uses_salary, 'version' => $template->version,
                'page' => $json($template->page), 'letterhead' => $json($template->letterhead), 'inputs' => $json($template->inputs), 'bodies' => $json($template->bodies),
            ]),
            'images' => $this->rows(LetterAsset::class, $organization, $organizationIds, fn (LetterAsset $asset) => [
                'id' => $asset->getKey(), 'kind' => $asset->kind, 'name' => $asset->name, 'mime' => $asset->mime, 'size_bytes' => $asset->size_bytes, 'is_active' => $asset->is_active,
            ]),
            'letters' => $this->rows(Letter::class, $organization, $organizationIds, fn (Letter $letter) => [
                'id' => $letter->getKey(), 'number' => $letter->number, 'kind' => $letter->kind, 'title' => $letter->title, 'locale' => $letter->locale,
                'employee_id' => $letter->employee_id, 'employee_code' => $letter->employee_code, 'employee_name' => $letter->employee_name,
                'template_id' => $letter->template_id, 'template_version' => $letter->template_version, 'shared' => $letter->shared,
                'issued_at' => $letter->issued_at->toIso8601String(), 'issued_by' => $letter->issued_by,
                'revoked_at' => $letter->revoked_at?->toIso8601String(), 'revoke_reason' => $letter->revoke_reason, 'content' => $json($letter->content),
            ]),
        ];
    }

    /**
     * @param  class-string<Model>  $model
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
