<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContentPageRequest;
use App\Http\Resources\AdminContentPageResource;
use App\Http\Resources\ContentPageResource;
use App\Models\ContentPage;
use App\Services\ContentPageService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ContentPageController extends Controller
{
    public function adminIndex(ContentPageService $pages): AnonymousResourceCollection
    {
        return AdminContentPageResource::collection($pages->all());
    }

    public function adminShow(string $slug, ContentPageService $pages): AdminContentPageResource
    {
        $this->assertSupportedSlug($slug);

        return new AdminContentPageResource($pages->find($slug) ?? new ContentPage(['slug' => $slug]));
    }

    public function update(ContentPageRequest $request, string $slug, ContentPageService $pages): AdminContentPageResource
    {
        $this->assertSupportedSlug($slug);

        return new AdminContentPageResource($pages->update($slug, $request->validated()));
    }

    public function publicShow(string $slug, ContentPageService $pages): ContentPageResource
    {
        $this->assertSupportedSlug($slug);
        $page = $pages->find($slug);

        if ($page === null) {
            throw (new ModelNotFoundException)->setModel(ContentPage::class, [$slug]);
        }

        return new ContentPageResource($page);
    }

    private function assertSupportedSlug(string $slug): void
    {
        if (! in_array($slug, config('content.page_slugs', []), true)) {
            throw (new ModelNotFoundException)->setModel(ContentPage::class, [$slug]);
        }
    }
}
