<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SeoAuditReportService;
use Illuminate\Http\JsonResponse;

final class AdminSeoAuditController extends Controller
{
    public function show(SeoAuditReportService $report): JsonResponse
    {
        return response()->json(['data' => $report->dashboardCounts()]);
    }
}
