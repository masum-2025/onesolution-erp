<?php

namespace App\Platform\Ai\Exceptions;

use RuntimeException;

/**
 * The model could not help this time (no key, too slow, refused, rate
 * limited, an answer of the wrong shape). Callers go on without AI; the
 * reason is a short code, never the text sent.
 */
class AiUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("AI unavailable: {$reason}");
    }
}
