<?php

namespace App\Platform\Attention\Http;

use App\Http\Controllers\Controller;
use App\Platform\Attention\AttentionCollector;
use App\Platform\Attention\AttentionItem;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Http\JsonResponse;

/**
 * The header bell for the current organization. Each item is a count and
 * the screen to open; that screen checks access again.
 */
class AttentionController extends Controller
{
    public function __invoke(CurrentContext $context, AttentionCollector $collector): JsonResponse
    {
        $items = $context->isSupport() ? [] : $collector->collect($context);

        return response()->json(['data' => array_map(fn (AttentionItem $item) => $item->toArray(), $items)]);
    }
}
