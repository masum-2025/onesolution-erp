<?php

namespace App\Platform\Attention;

/**
 * One line in the bell: what waits, how many, and the screen that handles it.
 * Counts only: never names or amounts, so nothing personal sits in the header.
 */
final readonly class AttentionItem
{
    public const TONES = ['info', 'warn', 'bad'];

    public function __construct(
        public string $key,
        public string $label,
        public int $count,
        public string $path,
        public string $tone = 'info',
    ) {}

    /**
     * @return array{key: string, label: string, count: int, path: string, tone: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'count' => $this->count,
            'path' => $this->path,
            'tone' => in_array($this->tone, self::TONES, true) ? $this->tone : 'info',
        ];
    }
}
