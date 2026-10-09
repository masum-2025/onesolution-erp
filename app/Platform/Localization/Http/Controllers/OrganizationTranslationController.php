<?php

namespace App\Platform\Localization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Localization\Exceptions\LocalizationException;
use App\Platform\Localization\Http\Controllers\Concerns\EditsWording;
use App\Platform\Localization\Http\Requests\ImportTextsRequest;
use App\Platform\Localization\Http\Requests\ListTextsRequest;
use App\Platform\Localization\Http\Requests\SaveTextRequest;
use App\Platform\Localization\LanguageRegistry;
use App\Platform\Localization\LocalizationState;
use App\Platform\Localization\ScopeChain;
use App\Platform\Localization\TranslationScope;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * A group's or company's own wording (multi_language module), for everyone
 * in it: "Grade" instead of "Class", their own name for a menu. Set by
 * people holding multi_language.manage there, while the partner allows
 * clients their own wording (rule i18n.allow_overrides).
 */
class OrganizationTranslationController extends Controller
{
    use EditsWording, FindsVisibleOrganizations;

    public function __construct(private ScopeChain $scopes, private LanguageRegistry $languages, private LocalizationState $state) {}

    /** The page's frame: languages offered here, and whether this level may be worded at all. */
    public function show(string $organization): JsonResponse
    {
        $target = $this->findVisible($organization);
        $this->authorizeManage($target);

        return response()->json(['data' => [
            'organization' => ['id' => $target->getKey(), 'name' => $target->displayName(), 'type' => $target->type->value],
            'can_hold_wording' => ScopeChain::canHoldWording($target),
            'own_wording' => ScopeChain::canHoldWording($target) && $this->scopes->ownWordingAllowed($target),
            'languages' => array_map(fn (string $code) => array_intersect_key(
                $this->languages->get($code),
                array_flip(['code', 'name', 'english_name', 'direction', 'source', 'fallback']),
            ), $this->state->offered()),
        ]]);
    }

    public function index(ListTextsRequest $request, string $organization): JsonResponse
    {
        [$target, $scope, $parents] = $this->level($organization);

        return $this->listTexts($request, $scope, $parents, $this->labels($target));
    }

    public function export(ListTextsRequest $request, string $organization): JsonResponse
    {
        [$target, $scope, $parents] = $this->level($organization);

        return $this->exportTexts($request, $scope, $parents, $this->labels($target));
    }

    public function update(SaveTextRequest $request, string $organization): JsonResponse
    {
        [$target, $scope] = $this->level($organization);

        return $this->saveText($request, $scope, $target->getKey(), $target->partner_id);
    }

    public function reset(SaveTextRequest $request, string $organization): JsonResponse
    {
        [$target, $scope] = $this->level($organization);

        return $this->resetText($request, $scope, $target->getKey(), $target->partner_id);
    }

    public function import(ImportTextsRequest $request, string $organization): JsonResponse
    {
        [$target, $scope] = $this->level($organization);

        return $this->importTexts($request, $scope, $target->getKey(), $target->partner_id);
    }

    /**
     * The organization as a wording level, its scope and the levels above it.
     *
     * @return array{0: Organization, 1: TranslationScope, 2: list<TranslationScope>}
     */
    private function level(string $organization): array
    {
        $target = $this->findVisible($organization);
        $this->authorizeManage($target);

        if (! ScopeChain::canHoldWording($target)) {
            throw LocalizationException::wrongLevel();
        }
        if (! $this->scopes->ownWordingAllowed($target)) {
            throw LocalizationException::ownWordingOff();
        }

        $scope = TranslationScope::organization($target);
        $chain = $this->scopes->forOrganization($target);
        $parents = array_values(array_filter($chain, fn (TranslationScope $level) => $level->key() !== $scope->key()));

        return [$target, $scope, $parents];
    }

    private function authorizeManage(Organization $target): void
    {
        if (! Gate::allows('multi_language.manage', $target)) {
            throw LocalizationException::forbidden();
        }
    }

    /**
     * Where an inherited text comes from, as people read it.
     *
     * @return array<string, array{level: string, name: string|null}>
     */
    private function labels(Organization $target): array
    {
        $labels = ['platform' => ['level' => 'platform', 'name' => null]];
        $partner = $target->partner()->first();
        if ($partner !== null) {
            $labels[TranslationScope::partner($partner)->key()] = ['level' => 'partner', 'name' => $partner->name];
        }
        $root = $target->isRoot() ? $target : Organization::query()->find($target->root_id);
        if ($root !== null) {
            $labels[TranslationScope::organization($root)->key()] = ['level' => $root->type->value, 'name' => $root->displayName()];
        }

        return $labels;
    }
}
