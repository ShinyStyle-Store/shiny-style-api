<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminSeoHistoryRequest;
use App\Http\Requests\AdminSeoRecordRequest;
use App\Http\Requests\AdminSeoSettingsRequest;
use App\Http\Resources\AdminSeoAuditResource;
use App\Http\Resources\AdminSeoRecordResource;
use App\Http\Resources\AdminSeoSettingResource;
use App\Models\SeoAudit;
use App\Models\User;
use App\Services\SeoRecordService;
use App\Services\SeoSettingsService;
use App\Services\SeoTargetService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminSeoController extends Controller
{
    public function __construct(
        private readonly SeoRecordService $records,
        private readonly SeoSettingsService $settings,
        private readonly SeoTargetService $targets,
    ) {}

    public function settings(): AnonymousResourceCollection
    {
        return AdminSeoSettingResource::collection($this->settings->all());
    }

    public function updateSettings(AdminSeoSettingsRequest $request): AnonymousResourceCollection
    {
        return AdminSeoSettingResource::collection(
            $this->settings->update($request->validated(), $request->user()),
        );
    }

    public function history(AdminSeoHistoryRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $audits = SeoAudit::query()
            ->with('actor')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return AdminSeoAuditResource::collection($audits);
    }

    public function show(string $type, int $id): AnonymousResourceCollection
    {
        return AdminSeoRecordResource::collection(
            $this->records->records($this->targets->findManageable($type, $id)),
        );
    }

    public function update(AdminSeoRecordRequest $request, string $type, int $id): AdminSeoRecordResource|\Illuminate\Http\JsonResponse
    {
        $record = $this->records->update(
            $this->targets->findManageable($type, $id),
            $request->validated(),
            $request->user(),
        );

        return $record === null
            ? response()->json(['data' => null])
            : new AdminSeoRecordResource($record);
    }
}
