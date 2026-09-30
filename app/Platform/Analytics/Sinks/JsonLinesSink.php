<?php

namespace App\Platform\Analytics\Sinks;

use App\Platform\Analytics\Contracts\AnalyticsSink;
use Illuminate\Support\Facades\Storage;

/**
 * One JSON object per line, one file per dataset and day (UTC), on a private
 * disk: for a log collector or a bulk loader to pick up.
 */
class JsonLinesSink implements AnalyticsSink
{
    public function name(): string
    {
        return 'jsonl';
    }

    public function send(string $dataset, array $rows): void
    {
        $lines = implode('', array_map(fn (array $row) => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", $rows));
        $path = trim((string) config('analytics.jsonl.path'), '/').'/'.$dataset.'/'.now('UTC')->format('Y-m-d').'.jsonl';

        Storage::disk((string) config('analytics.jsonl.disk'))->append($path, rtrim($lines, "\n"));
    }
}
