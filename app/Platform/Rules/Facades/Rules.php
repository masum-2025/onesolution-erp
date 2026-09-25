<?php

namespace App\Platform\Rules\Facades;

use App\Platform\Rules\ResolvedRule;
use App\Platform\Rules\RuleContext;
use App\Platform\Rules\RuleResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Facade;

/**
 * The only way module code reads business numbers:
 *
 *     Rules::get('attendance.late_grace_minutes');
 *
 * @method static mixed get(string $key, ?RuleContext $context = null, ?CarbonInterface $asOf = null)
 * @method static ResolvedRule resolve(string $key, ?RuleContext $context = null, ?CarbonInterface $asOf = null)
 * @method static ResolvedRule explain(string $key, ?RuleContext $context = null, ?CarbonInterface $asOf = null)
 * @method static array<string, mixed> getMany(string $prefix, ?RuleContext $context = null, ?CarbonInterface $asOf = null)
 * @method static array{rule_version: string, rules: array<string, mixed>} snapshot(array $keys, ?RuleContext $context = null)
 *
 * @see RuleResolver
 */
class Rules extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RuleResolver::class;
    }
}
