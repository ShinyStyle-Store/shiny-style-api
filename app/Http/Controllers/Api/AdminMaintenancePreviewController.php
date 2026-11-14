<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdminMaintenancePreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminMaintenancePreviewController extends Controller
{
    public function __invoke(Request $request, AdminMaintenancePreviewService $preview): JsonResponse
    {
        abort_unless(config('app.allow_data_cleanup'), 404);

        $token = $request->user()?->currentAccessToken();
        abort_unless(is_object($token) && is_array($token->abilities ?? null)
            && in_array('maintenance.cleanup', $token->abilities, true), 403);
        abort_unless($request->user()?->adminMembership?->role === 'owner', 403);

        $data = $request->validate([
            'targets' => ['required', 'array', 'min:1', 'max:2'],
            'targets.*' => ['required', 'string', 'in:archived_products,categories', 'distinct'],
            'filters' => ['required', 'array'],
            'filters.archived_products' => ['sometimes', 'array'],
            'filters.archived_products.ids' => ['sometimes', 'array', 'min:1', 'max:100'],
            'filters.archived_products.ids.*' => ['required', 'integer', 'distinct'],
            'filters.categories' => ['sometimes', 'array'],
            'filters.categories.ids' => ['sometimes', 'array', 'min:1', 'max:100'],
            'filters.categories.ids.*' => ['required', 'integer', 'distinct'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        if (! $request->boolean('dry_run', true)) {
            throw ValidationException::withMessages([
                'dry_run' => 'Execution is not enabled; this endpoint is preview-only pending security review.',
            ]);
        }

        foreach ($data['targets'] as $target) {
            if (empty($data['filters'][$target]['ids'])) {
                throw ValidationException::withMessages([
                    "filters.{$target}.ids" => 'Provide explicit IDs. Broad cleanup is not allowed.',
                ]);
            }
        }

        return response()->json($preview->preview($data['targets'], $data['filters']));
    }
}
