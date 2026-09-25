<?php

namespace App\Platform\Rules\Enums;

enum RuleMode: string
{
    // This level's value.
    case Set = 'set';
    // Bounds for descendants: {"min": .., "max": .., "allowed": [..]}.
    case Constrain = 'constrain';
    // Fixed value; descendants cannot override.
    case Lock = 'lock';

    /**
     * Set and lock share one slot (a level has a value or a locked value);
     * constrain is a separate slot.
     *
     * @return list<self>
     */
    public function slot(): array
    {
        return $this === self::Constrain ? [self::Constrain] : [self::Set, self::Lock];
    }
}
