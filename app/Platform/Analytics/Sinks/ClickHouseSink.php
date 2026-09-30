<?php

namespace App\Platform\Analytics\Sinks;

use App\Platform\Analytics\Contracts\AnalyticsSink;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * ClickHouse over its HTTP interface: one INSERT ... FORMAT JSONEachRow per
 * batch into "<database>.<dataset>". Credentials come from the environment;
 * a failed insert throws, so the cursor does not move.
 */
class ClickHouseSink implements AnalyticsSink
{
    public function name(): string
    {
        return 'clickhouse';
    }

    public function send(string $dataset, array $rows): void
    {
        $config = (array) config('analytics.clickhouse');

        if (empty($config['url'])) {
            throw new RuntimeException('ANALYTICS_CLICKHOUSE_URL is not set.');
        }
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $dataset) !== 1 || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $config['database']) !== 1) {
            throw new RuntimeException('Invalid ClickHouse database or dataset name.');
        }

        $body = implode("\n", array_map(fn (array $row) => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $rows));

        $response = Http::withBasicAuth((string) $config['username'], (string) $config['password'])
            ->timeout((int) $config['timeout'])
            ->withQueryParameters(['query' => "INSERT INTO {$config['database']}.{$dataset} FORMAT JSONEachRow"])
            ->withBody($body, 'application/x-ndjson')
            ->post((string) $config['url']);

        if ($response->failed()) {
            // The status only: ClickHouse error texts can quote the data.
            throw new RuntimeException("ClickHouse refused the batch (HTTP {$response->status()}).");
        }
    }
}
