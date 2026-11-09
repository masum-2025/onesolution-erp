<?php

/*
| AI (SC-4): the platform's one door to a language model. The key is the
| platform's (env); which model a company uses and how many calls a month
| are rules of the AI assistant module (ai_assistant.model, .monthly_calls).
*/

return [
    'api_key' => env('ANTHROPIC_API_KEY'),
    // Rule ai_assistant.model picks one of these names.
    'models' => [
        'haiku' => env('AI_MODEL_HAIKU', 'claude-haiku-5-5'),
        'sonnet' => env('AI_MODEL_SONNET', 'claude-sonnet-5-5'),
    ],
    // Seconds to wait; past it the caller goes on without AI (a customer is waiting in Messenger).
    'timeout' => (int) env('AI_TIMEOUT', 8),
    // A reading of one message is short JSON.
    'max_tokens' => 1024,
];
