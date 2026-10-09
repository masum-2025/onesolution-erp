<?php

namespace App\Platform\Localization\Enums;

/**
 * A database language: being translated (only its editors see it),
 * offered to people, or switched off (texts kept).
 */
enum LanguageStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Disabled = 'disabled';
}
