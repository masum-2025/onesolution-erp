<?php

namespace App\Platform\Ai\Contracts;

use App\Platform\Ai\AiReading;
use App\Platform\Ai\Exceptions\AiUnavailable;

/**
 * A language model that reads text into a fixed JSON shape (it never writes
 * to anyone: callers check every field and answer from their own data).
 * Bound to ClaudeReader; tests bind a fake.
 */
interface ReadsText
{
    /**
     * @param  string  $instructions  what to read and how (system prompt)
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     * @param  array<string, mixed>  $schema  JSON schema of the answer (object, additionalProperties false)
     * @param  string  $model  the model id (config ai.models)
     *
     * @throws AiUnavailable when there is no key, the model is slow or refuses, or the answer is not the shape asked for
     */
    public function read(string $instructions, array $messages, array $schema, string $model): AiReading;
}
