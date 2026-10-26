<?php

namespace Modules\Expenses\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Expenses\Exceptions\ExpensesException;
use Modules\Expenses\Models\Category;

/**
 * The company's kinds of expense. Each goes to one of the module's expense
 * posting keys (Accounting maps the keys to accounts); a mileage kind is
 * paid by the kilometre (expenses.mileage_rate). "Add the usual ones" adds
 * the kinds of the rule expenses.default_categories the company lacks.
 * Switched off, never removed (claims keep pointing at them).
 */
class Categories
{
    /** The expense posting keys a category may use (declared in the manifest). */
    public const POSTING_KEYS = ['expenses.travel', 'expenses.meals', 'expenses.office', 'expenses.communication', 'expenses.other'];

    public function __construct(
        private Place $place,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /** @return Collection<int, Category> */
    public function of(Organization $company, bool $activeOnly = false): Collection
    {
        return $this->place->query(Category::class, $company)->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')->orderBy('code')->get();
    }

    /**
     * @param  array{code: string, name: array<string, string>, posting_key: string, mileage?: bool, is_active?: bool, base_version?: int}  $data
     */
    public function save(Organization $company, ?Category $category, array $data, User $actor): Category
    {
        return $this->place->transaction($company, function () use ($company, $category, $data, $actor) {
            $code = strtoupper(trim($data['code']));
            $taken = $this->place->query(Category::class, $company)->where('code', $code)
                ->when($category !== null, fn ($query) => $query->whereKeyNot($category->getKey()))->exists();
            if ($taken) {
                throw ValidationException::withMessages(['code' => __('expenses::expenses.validation.code_taken')]);
            }
            if ($category !== null) {
                $category = $this->place->query(Category::class, $company)->whereKey($category->getKey())->lockForUpdate()->firstOrFail();
                if ($category->version !== (int) ($data['base_version'] ?? 0)) {
                    throw ExpensesException::versionConflict(['version' => $category->version]);
                }
            }
            $old = $category?->only(['code', 'posting_key', 'mileage', 'is_active']);
            $category ??= new Category(['organization_id' => $company->getKey(), 'version' => 0, 'sort_order' => $this->place->query(Category::class, $company)->count()]);
            $category->fill([
                'code' => $code, 'posting_key' => $data['posting_key'], 'mileage' => (bool) ($data['mileage'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);
            $category->putTexts('name', $data['name']);
            $category->forceFill(['version' => $category->version + 1])->save();
            $this->audit->record($old === null ? 'expenses.category_added' : 'expenses.category_changed', $category, old: $old ?? [],
                new: $category->only(['code', 'posting_key', 'mileage', 'is_active']), actor: $actor, organizationId: $company->getKey());

            return $category;
        });
    }

    /** The usual kinds (rule expenses.default_categories) the company does not have yet; how many were added. */
    public function addDefaults(Organization $company, User $actor): int
    {
        $have = $this->of($company)->pluck('code')->all();
        $added = 0;
        foreach ((array) $this->rules->get('expenses.default_categories', $this->contexts->forOrganization($company)) as $entry) {
            $code = strtoupper((string) ($entry['code'] ?? ''));
            if ($code === '' || in_array($code, $have, true) || ! in_array($entry['posting_key'] ?? '', self::POSTING_KEYS, true)) {
                continue;
            }
            $this->save($company, null, ['code' => $code, 'name' => (array) ($entry['name'] ?? []), 'posting_key' => $entry['posting_key'], 'mileage' => (bool) ($entry['mileage'] ?? false)], $actor);
            $added++;
        }

        return $added;
    }
}
