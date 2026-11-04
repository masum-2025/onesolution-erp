<?php

namespace Modules\Letters\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Modules\Letters\Exceptions\LettersException;
use Modules\Letters\Models\LetterAsset;
use Modules\Letters\Models\LetterTemplate;

/**
 * A company's letter designs and the images on them.
 *
 * - A design is made (from scratch, or a ready-made one from
 *   database/data/templates/*.php, by its key, once) and changed in place:
 *   its version counts up; letters already issued keep their own copy.
 * - Draft -> active (letters can be issued with it) -> retired (and back).
 * - A design with salary fields is made or changed only by people who may
 *   put salary in letters, where the company allows it.
 * - Images (letterhead, signature, stamp, logo) are checked (JPG, PNG,
 *   WebP, up to ASSET_MAX_KB), stored privately and shown through
 *   short-lived signed links; switched off, never deleted, so letters
 *   issued with them still print.
 */
class Templates
{
    public const ASSET_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public const ASSET_MAX_KB = 1024;

    public const LINK_MINUTES = 30;

    public function __construct(private Place $place, private LetterLayout $layout, private LetterFields $fields, private AuditLogger $audit) {}

    /**
     * Make ($template null) or change a design.
     *
     * @param  array<string, mixed>  $data  Validated by TemplateRequest.
     */
    public function save(Organization $company, ?LetterTemplate $template, ?int $baseVersion, array $data, User $actor): LetterTemplate
    {
        return $this->place->transaction($company, function () use ($company, $template, $baseVersion, $data, $actor) {
            if ($template !== null) {
                /** @var LetterTemplate $template */
                $template = $this->place->query(LetterTemplate::class, $company)->whereKey($template->getKey())->lockForUpdate()->firstOrFail();
                if ($template->version !== $baseVersion) {
                    throw LettersException::versionConflict(['version' => $template->version]);
                }
                $old = $this->values($template);
            } else {
                $template = new LetterTemplate;
                $template->fill(['organization_id' => $company->getKey(), 'status' => LetterTemplate::DRAFT, 'version' => 0, 'created_by' => $actor->getKey()]);
                $old = null;
            }

            if (array_key_exists('name', $data)) {
                $template->putTexts('name', $data['name']);
            }
            $design = ['kind', 'code', 'page', 'letterhead', 'inputs', 'bodies'];
            if ($old === null || array_intersect(array_keys($data), $design) !== []) {
                $checked = $this->layout->check($company, [
                    ...array_map(fn (string $key) => $template->{$key}, array_combine($design, $design)),
                    ...array_intersect_key($data, array_flip($design)),
                ]);
                $this->mayUseSalary($company, $checked['uses_salary']);
                $template->fill([...array_diff_key($checked, ['inputs' => true]), 'inputs' => $checked['inputs'] ?: null]);
            }
            if (array_key_exists('status', $data)) {
                $this->mayUseSalary($company, (bool) $template->uses_salary);
                $template->status = $data['status'];
            }
            $template->version++;
            $template->save();
            $this->audit->record($old === null ? 'letters.template_created' : 'letters.template_updated', $template,
                old: $old ?? [], new: $this->values($template), actor: $actor, organizationId: $company->getKey());

            return $template;
        });
    }

    /**
     * Ready-made designs (key, kind, name, description).
     *
     * @return list<array<string, mixed>>
     */
    public function presets(): array
    {
        $presets = [];
        foreach (glob(dirname(__DIR__, 2).'/database/data/templates/*.php') ?: [] as $file) {
            $preset = require $file;
            $presets[] = [...array_intersect_key($preset, array_flip(['key', 'kind', 'name', 'description'])), 'uses_salary' => $this->presetUsesSalary($preset)];
        }
        usort($presets, fn ($a, $b) => strcmp($a['key'], $b['key']));

        return $presets;
    }

    /**
     * Add a ready-made design as a draft (once: a second time gives the one made before).
     */
    public function applyPreset(Organization $company, string $key, User $actor): LetterTemplate
    {
        $file = dirname(__DIR__, 2)."/database/data/templates/{$key}.php";
        if (preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1 || ! is_file($file)) {
            throw LettersException::unknownPreset();
        }
        $existing = $this->place->query(LetterTemplate::class, $company)->where('preset', $key)->first();
        if ($existing !== null) {
            return $existing;
        }
        $preset = require $file;

        return $this->place->transaction($company, function () use ($company, $key, $preset, $actor) {
            $checked = $this->layout->check($company, $preset);
            $this->mayUseSalary($company, $checked['uses_salary']);
            $template = new LetterTemplate;
            $template->fill([
                ...array_diff_key($checked, ['inputs' => true]), 'inputs' => $checked['inputs'] ?: null,
                'organization_id' => $company->getKey(), 'preset' => $key, 'status' => LetterTemplate::DRAFT, 'version' => 1, 'created_by' => $actor->getKey(),
            ]);
            $template->putTexts('name', $preset['name']);
            $template->save();
            $this->audit->record('letters.preset_applied', $template, new: ['key' => $key], actor: $actor, organizationId: $company->getKey());

            return $template;
        });
    }

    public function addAsset(Organization $company, string $kind, string $name, UploadedFile $file, User $actor): LetterAsset
    {
        if (! in_array($file->getMimeType(), self::ASSET_TYPES, true) || $file->getSize() > self::ASSET_MAX_KB * 1024) {
            throw LettersException::badAsset(self::ASSET_MAX_KB);
        }
        $path = $file->storeAs("letters/{$company->getKey()}/assets", Str::ulid().'.'.($file->guessExtension() ?? 'png'), 'local');

        return $this->place->transaction($company, function () use ($company, $kind, $name, $file, $path, $actor) {
            $asset = new LetterAsset;
            $asset->fill([
                'organization_id' => $company->getKey(), 'kind' => $kind, 'name' => $name, 'path' => $path,
                'mime' => (string) $file->getMimeType(), 'size_bytes' => (int) $file->getSize(), 'is_active' => true, 'created_by' => $actor->getKey(),
            ]);
            $asset->save();
            $this->audit->record('letters.asset_added', $asset, new: $asset->only(['kind', 'name', 'mime', 'size_bytes']), actor: $actor, organizationId: $company->getKey());

            return $asset;
        });
    }

    /** @param  array{name?: string, is_active?: bool}  $data */
    public function updateAsset(Organization $company, LetterAsset $asset, array $data, User $actor): LetterAsset
    {
        $old = $asset->only(['name', 'is_active']);
        $asset->fill(array_intersect_key($data, array_flip(['name', 'is_active'])))->save();
        $this->audit->record('letters.asset_updated', $asset, old: $old, new: $asset->only(['name', 'is_active']), actor: $actor, organizationId: $company->getKey());

        return $asset;
    }

    /** A link that shows an image for a while (long enough to design or print). */
    public function assetLink(LetterAsset $asset): string
    {
        return URL::temporarySignedRoute('letters.asset', now()->addMinutes(self::LINK_MINUTES), [
            'organization' => $asset->organization_id,
            'asset' => $asset->getKey(),
        ], absolute: false);
    }

    /**
     * Signed links of the images a letterhead and bodies use, by id.
     *
     * @param  array<string, mixed>  $letterhead
     * @param  array<string, list<array<string, mixed>>>  $bodies
     * @return array<string, string>
     */
    public function assetLinks(Organization $company, array $letterhead, array $bodies): array
    {
        $ids = [$letterhead['image_id'] ?? null];
        foreach ($bodies as $blocks) {
            foreach ($blocks as $block) {
                $ids[] = $block['image_id'] ?? null;
                $ids[] = $block['stamp_id'] ?? null;
            }
        }
        $ids = array_values(array_unique(array_filter($ids, 'is_string')));
        if ($ids === []) {
            return [];
        }

        return $this->place->query(LetterAsset::class, $company)->whereKey($ids)->get()
            ->mapWithKeys(fn (LetterAsset $asset) => [$asset->getKey() => $this->assetLink($asset)])->all();
    }

    /** Salary in a design: only where the company allows it, and only by people who may. */
    private function mayUseSalary(Organization $company, bool $usesSalary): void
    {
        if (! $usesSalary) {
            return;
        }
        if (! $this->fields->salaryEnabled($company)) {
            throw LettersException::salaryOff();
        }
        if (! $this->fields->salaryAllowed($company)) {
            throw LettersException::salaryNotAllowed();
        }
    }

    /** @param  array<string, mixed>  $preset */
    private function presetUsesSalary(array $preset): bool
    {
        return str_contains(json_encode($preset['bodies'] ?? []) ?: '', '{pay.') || str_contains(json_encode($preset['bodies'] ?? []) ?: '', '"pay.');
    }

    /** @return array<string, mixed> */
    private function values(LetterTemplate $template): array
    {
        return [
            ...$template->only(['kind', 'code', 'status', 'uses_salary', 'version']),
            'name' => $template->texts('name'),
            'blocks' => collect((array) $template->bodies)->map(fn ($blocks) => count((array) $blocks))->all(),
        ];
    }
}
