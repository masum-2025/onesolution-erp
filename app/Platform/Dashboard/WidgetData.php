<?php

namespace App\Platform\Dashboard;

/**
 * The shapes the app can draw. Widgets build their answer here so every
 * module's dashboard looks and behaves the same (and stays readable for
 * colour-blind people: a change always has an arrow and a sign, not only a color).
 */
final class WidgetData
{
    /**
     * A whole number (a count, or money in minor units with its currency) with an
     * optional change and a small trend line. Never a float: money stays exact.
     *
     * @param  int|null  $change  Difference from the previous period.
     * @param  bool|null  $upIsGood  Whether a rise is good news (null = neither, e.g. headcount).
     * @param  list<int>  $series  Oldest first, for the trend line.
     * @param  'number'|'money'  $format
     */
    public static function stat(
        int $value,
        ?string $hint = null,
        ?int $change = null,
        ?bool $upIsGood = null,
        array $series = [],
        string $format = 'number',
        ?string $currency = null,
    ): array {
        return [
            'value' => $value,
            'format' => $format,
            'currency' => $currency,
            'hint' => $hint,
            'change' => $change === null ? null : [
                'value' => $change,
                'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat'),
                // good | bad | neutral: the tone of the change badge.
                'tone' => $change === 0 || $upIsGood === null ? 'neutral' : ((($change > 0) === $upIsGood) ? 'good' : 'bad'),
            ],
            'series' => array_values($series),
        ];
    }

    /**
     * Horizontal bars, largest first.
     *
     * @param  list<array{label: string, value: int}>  $items
     */
    public static function bars(array $items, ?string $hint = null): array
    {
        usort($items, fn (array $a, array $b) => $b['value'] <=> $a['value']);

        return ['items' => array_values($items), 'hint' => $hint];
    }

    /**
     * A short list of things, each optionally opening a screen.
     *
     * @param  list<array{label: string, meta?: string|null, date?: string|null, path?: string|null, tone?: string|null}>  $items  `date` (Y-m-d) is written in the reader's language by the app.
     */
    public static function list(array $items): array
    {
        return ['items' => array_values(array_map(fn (array $item) => [
            'label' => $item['label'],
            'meta' => $item['meta'] ?? null,
            'date' => $item['date'] ?? null,
            'path' => $item['path'] ?? null,
            'tone' => $item['tone'] ?? null,
        ], $items))];
    }
}
