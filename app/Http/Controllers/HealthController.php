<?php

namespace App\Http\Controllers;

use App\Support\Observability\HealthChecker;
use Illuminate\Http\JsonResponse;

/**
 * Operational readiness endpoint (WS-7). Does not expose credentials or secrets.
 */
class HealthController extends Controller
{
    public function ready(HealthChecker $checker): JsonResponse
    {
        $result = $checker->readiness();
        $status = $result['status'] === 'ready' ? 200 : 503;

        return response()->json($result, $status);
    }
}
