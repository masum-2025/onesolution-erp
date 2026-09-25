<?php

namespace App\Platform\Rules;

use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\Enums\RuleValueStatus;
use App\Platform\Rules\Models\RuleValue;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Resolves rule values through a chain of levels:
 *
 *  a. start with the definition default;
 *  b. apply platform > partner > plan > group > company > branch > department
 *     > role > user, using only values in effect at the given moment;
 *  c. a lock stops the walk: lower levels are ignored;
 *  d. the result must satisfy every ancestor constraint, otherwise the nearest
 *     valid ancestor value is used (and a warning is logged);
 *  e. for country-specific rules, a value for the chain's country beats the
 *     generic value on the same level.
 */
class RuleResolver
{
    /** @var array<string, array{rules: array<string, ResolvedRule>, valid_until: int}> */
    private array $memo = [];

    public function __construct(
        private RuleCatalog $catalog,
        private RuleContextFactory $contexts,
        private RuleValueValidator $validator,
        private RuleCache $cache,
    ) {}

    /**
     * The value of a rule for the current tenant (or the given chain / date).
     */
    public function get(string $key, ?RuleContext $context = null, ?CarbonInterface $asOf = null): mixed
    {
        return $this->resolve($key, $context, $asOf)->value;
    }

    public function resolve(string $key, ?RuleContext $context = null, ?CarbonInterface $asOf = null): ResolvedRule
    {
        $this->catalog->get($key);

        return $this->all($context, $asOf)[$key];
    }

    /**
     * Same as resolve(); named for the "full trace" use case.
     */
    public function explain(string $key, ?RuleContext $context = null, ?CarbonInterface $asOf = null): ResolvedRule
    {
        return $this->resolve($key, $context, $asOf);
    }

    /**
     * Values of every rule whose key starts with the prefix, e.g. "payroll.".
     *
     * @return array<string, mixed>
     */
    public function getMany(string $prefix, ?RuleContext $context = null, ?CarbonInterface $asOf = null): array
    {
        $values = [];

        foreach ($this->all($context, $asOf) as $key => $resolved) {
            if (str_starts_with($key, $prefix)) {
                $values[$key] = $resolved->value;
            }
        }

        return $values;
    }

    /**
     * The rules a device needs offline, with a version to detect staleness.
     *
     * @param  list<string>  $keys
     * @return array{rule_version: string, rules: array<string, mixed>}
     */
    public function snapshot(array $keys, ?RuleContext $context = null): array
    {
        $context ??= $this->contexts->current();
        $all = $this->all($context);

        return [
            'rule_version' => $context->fingerprint().':'.$this->cache->versionTag($context),
            'rules' => array_map(fn (string $key) => $all[$key]->value, array_combine($keys, $keys)),
        ];
    }

    /**
     * @return array<string, ResolvedRule>
     */
    public function all(?RuleContext $context = null, ?CarbonInterface $asOf = null): array
    {
        $context ??= $this->contexts->current();

        // Point-in-time lookups (payroll back-calculation) skip the cache.
        if ($asOf !== null) {
            return $this->fresh($context, $asOf);
        }

        $memoKey = $context->fingerprint().':'.$this->cache->versionTag($context);
        $memo = $this->memo[$memoKey] ?? null;

        if ($memo === null || $memo['valid_until'] <= now()->getTimestamp()) {
            $payload = $this->cache->remember($context, function () use ($context) {
                $map = array_map(fn (ResolvedRule $rule) => $rule->toArray(), $this->fresh($context));

                return [$map, $this->secondsToNextBoundary($context)];
            });

            $memo = $this->memo[$memoKey] = [
                'rules' => array_map(fn (array $data) => ResolvedRule::fromArray($data), $payload['map']),
                'valid_until' => $payload['valid_until'],
            ];
        }

        return $memo['rules'];
    }

    /**
     * Resolve from the database, bypassing caches.
     *
     * @param  list<RuleValue>  $overrides  Unsaved values that replace stored ones in the
     *                                      same scope and slot (used by "preview impact").
     * @return array<string, ResolvedRule>
     */
    public function fresh(RuleContext $context, ?CarbonInterface $asOf = null, array $overrides = []): array
    {
        $asOf ??= now();

        $rows = $this->chainQuery($context)->effectiveAt($asOf)->get();

        foreach ($overrides as $override) {
            $rows = $rows->reject(fn (RuleValue $row) => $row->rule_key === $override->rule_key
                && $row->scope_type === $override->scope_type
                && $row->scope_id === $override->scope_id
                && $row->country_code === $override->country_code
                && in_array($row->mode, $override->mode->slot(), true));
            $rows->push($override);
        }

        $byKey = $rows->groupBy('rule_key');
        $resolved = [];

        foreach ($this->catalog->all() as $key => $rule) {
            $resolved[$key] = $this->resolveOne($rule, $byKey->get($key, new Collection), $context);
        }

        return $resolved;
    }

    /**
     * @param  Collection<int, RuleValue>  $rows
     */
    private function resolveOne(RuleDefinition $rule, Collection $rows, RuleContext $context): ResolvedRule
    {
        $targetIndex = $context->targetIndex();
        $candidates = [['index' => -1, 'value' => $rule->default, 'level' => null]];
        $constraints = [];
        $lock = null;
        $trace = [['level' => 'default', 'scope_id' => null, 'name' => null, 'value' => $rule->default]];

        foreach ($context->levels as $index => $level) {
            $here = $rows->filter(fn (RuleValue $row) => $row->scope_type === $level->scope && $row->scope_id === $level->scopeId);
            $pick = fn (RuleMode $mode) => $here
                ->filter(fn (RuleValue $row) => $row->mode === $mode)
                ->sortByDesc(fn (RuleValue $row) => [$row->country_code !== null ? 1 : 0, $row->effective_from?->getTimestamp() ?? 0])
                ->first();

            $lockRow = $pick(RuleMode::Lock);
            $setRow = $lockRow === null ? $pick(RuleMode::Set) : null;
            $constrainRow = $lockRow === null ? $pick(RuleMode::Constrain) : null;

            $entry = [
                'level' => $level->scope->value,
                'scope_id' => $level->scopeId,
                'name' => $level->name,
                'set' => $setRow?->value,
                'lock' => $lockRow?->value,
                'constrain' => $constrainRow?->value,
                'country_code' => ($lockRow ?? $setRow ?? $constrainRow)?->country_code,
                'note' => null,
            ];

            $valueRow = $lockRow ?? $setRow;
            if ($valueRow !== null) {
                if ($this->validator->validate($rule, $valueRow->value) === null) {
                    $candidates[] = ['index' => $index, 'value' => $valueRow->value, 'level' => $level];
                } else {
                    $entry['note'] = 'ignored_invalid_value';
                }
            }

            if ($constrainRow !== null && $index < $targetIndex) {
                $constraints[] = ['index' => $index, 'bounds' => $constrainRow->value, 'level' => $level];
            }

            $trace[] = $entry;

            if ($lockRow !== null) {
                $lock = ['index' => $index, 'level' => $level];
                break;
            }
        }

        $bounds = $this->validator->combine($rule, array_column($constraints, 'bounds'));
        $chosen = null;

        foreach (array_reverse($candidates) as $candidate) {
            if ($this->validator->satisfies($rule, $candidate['value'], $bounds)) {
                $chosen = $candidate;
                break;
            }
        }

        $fellBack = $chosen !== end($candidates);

        if ($chosen === null) {
            $nearestConstraint = end($constraints)['level'];
            $chosen = ['index' => -1, 'value' => $this->validator->clamp($rule, end($candidates)['value'], $bounds), 'level' => $nearestConstraint];
        }

        if ($fellBack) {
            Log::warning('Rule value does not satisfy an ancestor constraint; using a fallback.', [
                'rule' => $rule->key,
                'target_level' => $context->target()->scope->value,
                'target_scope_id' => $context->target()->scopeId,
            ]);
        }

        /** @var RuleLevel|null $source */
        $source = $chosen['level'];
        $lockedByAncestor = $lock !== null && $lock['index'] < $targetIndex;

        return new ResolvedRule(
            key: $rule->key,
            value: $chosen['value'],
            sourceLevel: $source?->scope->value,
            sourceScopeId: $source?->scopeId,
            sourceName: $source?->name,
            lockedByLevel: $lockedByAncestor ? $lock['level']->scope->value : null,
            lockedByScopeId: $lockedByAncestor ? $lock['level']->scopeId : null,
            lockedByName: $lockedByAncestor ? $lock['level']->name : null,
            lockedHere: $lock !== null && $lock['index'] === $targetIndex,
            constraints: $bounds,
            fellBack: $fellBack,
            trace: $trace,
        );
    }

    /**
     * @return Builder<RuleValue>
     */
    private function chainQuery(RuleContext $context): Builder
    {
        return RuleValue::query()
            ->where(function (Builder $query) use ($context) {
                foreach ($context->levels as $level) {
                    $query->orWhere(fn (Builder $q) => $q->where('scope_type', $level->scope)->where('scope_id', $level->scopeId));
                }
            })
            ->where(function (Builder $query) use ($context) {
                $query->whereNull('country_code');
                if ($context->countryCode !== null) {
                    $query->orWhere('country_code', $context->countryCode);
                }
            });
    }

    /**
     * Seconds until a stored value in this chain starts or stops being effective.
     */
    private function secondsToNextBoundary(RuleContext $context): ?int
    {
        $now = now();
        $rows = $this->chainQuery($context)
            ->where('status', RuleValueStatus::Active)
            ->where(fn (Builder $q) => $q->where('effective_from', '>', $now)->orWhere('effective_to', '>', $now))
            ->get(['effective_from', 'effective_to']);

        $next = $rows
            ->flatMap(fn (RuleValue $row) => [$row->effective_from, $row->effective_to])
            ->filter(fn (?CarbonInterface $moment) => $moment !== null && $moment->gt($now))
            ->min();

        return $next === null ? null : (int) ceil($now->diffInSeconds($next));
    }
}
