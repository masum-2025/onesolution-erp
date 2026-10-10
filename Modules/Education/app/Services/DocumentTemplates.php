<?php

namespace Modules\Education\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Modules\Education\Exceptions\EducationException;
use Modules\Education\Models\DocumentAsset;
use Modules\Education\Models\DocumentTemplate;

/**
 * The institution's designs and the images on them.
 *
 * - A design is made (from scratch, or a ready-made one from
 *   database/data/documents/*.php, by its key, once) and changed in place:
 *   its version counts up; documents already issued keep their own copy.
 * - Draft -> active (documents can be issued with it) -> retired.
 * - Images are checked (JPG, PNG, WebP, up to ASSET_MAX_KB), stored
 *   privately and shown through short-lived signed links; switched off,
 *   never deleted, so documents issued with them still print.
 */
class DocumentTemplates
{
    public const ASSET_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public const ASSET_MAX_KB = 1024;

    public const LINK_MINUTES = 30;

    public function __construct(private Education $education, private DocumentLayout $layout, private AuditLogger $audit) {}

    /**
     * Make ($template null) or change a design.
     *
     * @param  array<string, mixed>  $data  Validated by DocumentTemplateRequest.
     */
    public function save(Organization $company, ?DocumentTemplate $template, ?int $baseVersion, array $data, User $actor): DocumentTemplate
    {
        return $this->education->transaction($company, function () use ($company, $template, $baseVersion, $data, $actor) {
            if ($template !== null) {
                /** @var DocumentTemplate $template */
                $template = $this->education->query(DocumentTemplate::class, $company)->whereKey($template->getKey())->lockForUpdate()->firstOrFail();
                if ($template->version !== $baseVersion) {
                    throw EducationException::versionConflict(['version' => $template->version]);
                }
                $old = $this->values($template);
            } else {
                $template = new DocumentTemplate;
                $template->fill(['organization_id' => $company->getKey(), 'kind' => $data['kind'], 'status' => 'draft', 'version' => 0, 'created_by' => $actor->getKey()]);
                $old = null;
            }

            if (array_key_exists('name', $data)) {
                $template->putTexts('name', $data['name']);
            }
            foreach (['locale', 'status'] as $field) {
                if (array_key_exists($field, $data)) {
                    $template->{$field} = $data[$field];
                }
            }
            if (array_key_exists('page', $data) || array_key_exists('layout', $data) || array_key_exists('inputs', $data) || $old === null) {
                $checked = $this->layout->check(
                    $company,
                    (array) ($data['page'] ?? $template->page ?? []),
                    (array) ($data['layout'] ?? $template->layout ?? []),
                    array_values((array) ($data['inputs'] ?? $template->inputs ?? [])),
                );
                $template->fill(['page' => $checked['page'], 'layout' => $checked['layout'], 'inputs' => $checked['inputs'] ?: null]);
            }
            $template->version++;
            $template->save();
            $this->audit->record($old === null ? 'education.document_template_created' : 'education.document_template_updated', $template,
                old: $old ?? [], new: $this->values($template), actor: $actor, organizationId: $company->getKey());

            return $template;
        });
    }

    /**
     * Ready-made designs (key, kind, name, description, locale, sectors).
     *
     * @return list<array<string, mixed>>
     */
    public function presets(): array
    {
        $presets = [];
        foreach (glob(dirname(__DIR__, 2).'/database/data/documents/*.php') ?: [] as $file) {
            $preset = require $file;
            $presets[] = array_intersect_key($preset, array_flip(['key', 'kind', 'name', 'description', 'locale', 'sectors']));
        }
        usort($presets, fn ($a, $b) => strcmp($a['key'], $b['key']));

        return $presets;
    }

    /**
     * Add a ready-made design as a draft (once: a second time gives the one made before).
     */
    public function applyPreset(Organization $company, string $key, User $actor): DocumentTemplate
    {
        $file = dirname(__DIR__, 2)."/database/data/documents/{$key}.php";
        if (preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1 || ! is_file($file)) {
            throw EducationException::unknownDocumentPreset();
        }
        $existing = $this->education->query(DocumentTemplate::class, $company)->where('key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }
        $preset = require $file;

        return $this->education->transaction($company, function () use ($company, $key, $preset, $actor) {
            $checked = $this->layout->check($company, $preset['page'], $preset['layout'], $preset['inputs'] ?? []);
            $template = new DocumentTemplate;
            $template->fill([
                'organization_id' => $company->getKey(), 'key' => $key, 'kind' => $preset['kind'], 'locale' => $preset['locale'],
                'page' => $checked['page'], 'layout' => $checked['layout'], 'inputs' => $checked['inputs'] ?: null,
                'status' => 'draft', 'version' => 1, 'created_by' => $actor->getKey(),
            ]);
            $template->putTexts('name', $preset['name']);
            $template->save();
            $this->audit->record('education.document_preset_applied', $template, new: ['key' => $key], actor: $actor, organizationId: $company->getKey());

            return $template;
        });
    }

    public function addAsset(Organization $company, string $kind, string $name, UploadedFile $file, User $actor): DocumentAsset
    {
        if (! in_array($file->getMimeType(), self::ASSET_TYPES, true) || $file->getSize() > self::ASSET_MAX_KB * 1024) {
            throw EducationException::badAsset(self::ASSET_MAX_KB);
        }
        $path = $file->storeAs("education/{$company->getKey()}/document-assets", Str::ulid().'.'.($file->guessExtension() ?? 'png'), 'local');

        return $this->education->transaction($company, function () use ($company, $kind, $name, $file, $path, $actor) {
            $asset = new DocumentAsset;
            $asset->fill([
                'organization_id' => $company->getKey(), 'kind' => $kind, 'name' => $name, 'path' => $path,
                'mime' => (string) $file->getMimeType(), 'size_bytes' => (int) $file->getSize(), 'is_active' => true, 'created_by' => $actor->getKey(),
            ]);
            $asset->save();
            $this->audit->record('education.document_asset_added', $asset, new: $asset->only(['kind', 'name', 'mime', 'size_bytes']), actor: $actor, organizationId: $company->getKey());

            return $asset;
        });
    }

    /** @param  array{name?: string, is_active?: bool}  $data */
    public function updateAsset(Organization $company, DocumentAsset $asset, array $data, User $actor): DocumentAsset
    {
        $old = $asset->only(['name', 'is_active']);
        $asset->fill(array_intersect_key($data, array_flip(['name', 'is_active'])))->save();
        $this->audit->record('education.document_asset_updated', $asset, old: $old, new: $asset->only(['name', 'is_active']), actor: $actor, organizationId: $company->getKey());

        return $asset;
    }

    /** A link that shows an image for a while (long enough to design or print). */
    public function assetLink(DocumentAsset $asset): string
    {
        return URL::temporarySignedRoute('education.document-asset', now()->addMinutes(self::LINK_MINUTES), [
            'organization' => $asset->organization_id,
            'asset' => $asset->getKey(),
        ], absolute: false);
    }

    /**
     * Signed links of the images a layout uses, by id.
     *
     * @param  array<string, mixed>  $layout
     * @return array<string, string>
     */
    public function assetLinks(Organization $company, array $layout): array
    {
        $ids = array_filter([$layout['background']['asset_id'] ?? null, ...array_map(fn ($element) => $element['asset_id'] ?? null, (array) ($layout['elements'] ?? []))]);
        if ($ids === []) {
            return [];
        }

        return $this->education->query(DocumentAsset::class, $company)->whereKey(array_values(array_unique($ids)))->get()
            ->mapWithKeys(fn (DocumentAsset $asset) => [$asset->getKey() => $this->assetLink($asset)])->all();
    }

    /** @return array<string, mixed> */
    private function values(DocumentTemplate $template): array
    {
        return [...$template->only(['kind', 'locale', 'status', 'version']), 'name' => $template->texts('name'), 'elements' => count($template->layout['elements'] ?? [])];
    }
}
