<?php

namespace App\Platform\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Messages\TextBlock;
use App\Platform\Ai\Contracts\ReadsText;
use App\Platform\Ai\Exceptions\AiUnavailable;

/**
 * ReadsText through the Claude API (official PHP SDK), with structured JSON
 * output so the answer always has the asked shape. Any trouble becomes
 * AiUnavailable with a short reason; the text sent is never logged.
 */
final class ClaudeReader implements ReadsText
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly int $timeout,
        private readonly int $maxTokens,
    ) {}

    public function read(string $instructions, array $messages, array $schema, string $model): AiReading
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw new AiUnavailable('no_key');
        }

        $client = new Client(apiKey: $this->apiKey, requestOptions: ['timeout' => $this->timeout, 'maxRetries' => 1]);

        try {
            $reply = $client->messages->create(
                maxTokens: $this->maxTokens,
                messages: $messages,
                model: $model,
                system: $instructions,
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $schema]],
            );
        } catch (RateLimitException) {
            throw new AiUnavailable('rate_limited');
        } catch (AuthenticationException) {
            throw new AiUnavailable('bad_key');
        } catch (APIConnectionException) {
            throw new AiUnavailable('timeout');
        } catch (AnthropicException) {
            throw new AiUnavailable('api_error');
        }

        if ($reply->stopReason === 'refusal') {
            throw new AiUnavailable('refused');
        }
        if ($reply->stopReason === 'max_tokens') {
            throw new AiUnavailable('cut_off');
        }

        $text = null;
        foreach ($reply->content as $block) {
            if ($block instanceof TextBlock) {
                $text = $block->text;
                break;
            }
        }
        $data = $text === null ? null : json_decode($text, true);
        if (! is_array($data)) {
            throw new AiUnavailable('bad_shape');
        }

        return new AiReading($data, $reply->usage->inputTokens, $reply->usage->outputTokens);
    }
}
