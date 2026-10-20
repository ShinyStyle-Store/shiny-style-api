<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SeoAuditReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminSeoAuditController extends Controller
{
    public function show(Request $request, SeoAuditReportService $report): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json(['data' => $report->dashboard(
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? 20),
        )]);
    }
}
