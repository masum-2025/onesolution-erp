<?php

namespace App\Platform\Analytics\Sinks;

use App\Platform\Analytics\Contracts\AnalyticsSink;

/** No analytics store (default): nothing is sent, nothing is kept. */
class NullSink implements AnalyticsSink
{
    public function name(): string
    {
        return 'none';
    }

    public function send(string $dataset, array $rows): void {}
}
