<?php

namespace App\Platform\Appearance;

use App\Models\User;
use App\Platform\Rules\Facades\Rules;
use App\Platform\Rules\ResolvedRule;
use App\Platform\Rules\RuleCatalog;

/**
 * How the app looks for one person in the current context.
 *
 * Template and highlight color: a level that LOCKS the rule decides for
 * everyone below; otherwise the person's own choice wins when it is inside
 * what the levels above allow, else the organization's value applies.
 * Readability (colour vision, contrast) is never decided by an organization:
 * someone who needs it must always be able to turn it on.
 */
final class AppearanceResolver
{
    /** Rule keys of the choices an organization may lock, by preference name. */
    public const RULES = ['template' => 'ui.shell_template', 'accent' => 'ui.accent'];

    public const COLOR_VISION = ['standard', 'blue_orange'];

    public const CONTRAST = ['standard', 'high'];

    public function __construct(private RuleCatalog $catalog) {}

    /**
     * Every value a preference may take (the rule's list for template and accent).
     *
     * @return list<string>
     */
    public function options(string $preference): array
    {
        return match ($preference) {
            'color_vision' => self::COLOR_VISION,
            'contrast' => self::CONTRAST,
            default => $this->catalog->get(self::RULES[$preference])->schema['enum'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $own = $user->ui_preferences ?? [];
        $result = [];

        foreach (self::RULES as $preference => $rule) {
            $result[$preference] = $this->choice($preference, Rules::resolve($rule), $own[$preference] ?? null);
        }

        foreach (['color_vision' => self::COLOR_VISION, 'contrast' => self::CONTRAST] as $preference => $options) {
            $value = $own[$preference] ?? null;
            $result[$preference] = in_array($value, $options, true) ? $value : $options[0];
        }

        return $result;
    }

    /**
     * @return array{value: string, source: string, level: string|null, name: string|null, locked: bool, own: string|null, allowed: list<string>}
     */
    private function choice(string $preference, ResolvedRule $rule, mixed $own): array
    {
        $locked = $rule->lockedHere || $rule->isLockedByAncestor();
        $allowed = array_values(array_intersect($this->options($preference), $rule->constraints['allowed'] ?? $this->options($preference)));
        $usable = ! $locked && is_string($own) && in_array($own, $allowed, true);

        return [
            'value' => $usable ? $own : (string) $rule->value,
            // user = the person's own choice; organization = set (or locked) above; default = nobody set it.
            'source' => $usable ? 'user' : ($rule->sourceLevel === null ? 'default' : 'organization'),
            'level' => $locked ? ($rule->lockedByLevel ?? $rule->sourceLevel) : $rule->sourceLevel,
            'name' => $locked ? ($rule->lockedByName ?? $rule->sourceName) : $rule->sourceName,
            'locked' => $locked,
            'own' => is_string($own) ? $own : null,
            'allowed' => $locked ? [(string) $rule->value] : $allowed,
        ];
    }
}
