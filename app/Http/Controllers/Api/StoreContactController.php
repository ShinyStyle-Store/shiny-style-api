<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContactRequest;
use App\Http\Resources\AdminStoreContactResource;
use App\Http\Resources\StoreContactResource;
use App\Models\StoreContact;
use App\Services\StoreContactService;

class StoreContactController extends Controller
{
    public function showPublic(StoreContactService $contacts): StoreContactResource
    {
        return new StoreContactResource($contacts->current() ?? new StoreContact);
    }

    public function adminShow(StoreContactService $contacts): AdminStoreContactResource
    {
        return new AdminStoreContactResource($contacts->current() ?? new StoreContact);
    }

    public function update(StoreContactRequest $request, StoreContactService $contacts): AdminStoreContactResource
    {
        return new AdminStoreContactResource($contacts->update($request->validated()));
    }
}
