<?php

namespace App\Platform\Monitoring\Http;

use App\Http\Controllers\Controller;
use App\Platform\Monitoring\HealthReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /internal/health for monitoring tools (Phase 9-2): counts and times
 * only. Needs "Authorization: Bearer <HEALTH_TOKEN>"; without a configured
 * token, or with a wrong one, the endpoint does not exist (404). Answers
 * 503 while a check fails, so uptime tools alert on the status alone.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request, HealthReport $health): JsonResponse
    {
        $token = (string) config('monitoring.health.token');

        abort_if($token === '' || ! hash_equals($token, (string) $request->bearerToken()), 404);

        $report = $health->run();

        return response()->json($report, $report['status'] === 'fail' ? 503 : 200, ['Cache-Control' => 'no-store']);
    }
}
