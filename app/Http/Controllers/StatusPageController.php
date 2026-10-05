<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\OrganizationAccess;
use App\Http\Requests\PageRequest;
use App\Http\Requests\StatusPageRequest;
use App\Http\Resources\StatusPageResource;
use App\Models\StatusPage;
use App\Services\Quota;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StatusPageController extends Controller
{
    public function index(PageRequest $request): AnonymousResourceCollection
    {
        return StatusPageResource::collection(StatusPage::where('organization_id', OrganizationAccess::organization($request)->id)->with('monitors:id')->latest('id')->paginate($request->pageSize()));
    }

    public function store(StatusPageRequest $request, Quota $quota): StatusPageResource
    {
        $organization = OrganizationAccess::organization($request);
        $page = $quota->create($organization, 'status_pages', function () use ($organization, $request): StatusPage {
            $page = StatusPage::create([...$request->safe()->except('components'), 'organization_id' => $organization->id]);
            $this->sync($page, $request->validated('components'));

            return $page;
        });

        return new StatusPageResource($page->refresh()->load('monitors:id'));
    }

    public function show(StatusPage $statusPage): StatusPageResource
    {
        return new StatusPageResource($statusPage->load('monitors:id'));
    }

    public function update(StatusPageRequest $request, StatusPage $statusPage): StatusPageResource
    {
        $oldSlug = $statusPage->slug;
        DB::transaction(function () use ($request, $statusPage): void {
            StatusPage::whereKey($statusPage->id)->lockForUpdate()->firstOrFail();
            $statusPage->update($request->safe()->except('components'));
            if ($request->has('components')) {
                $this->sync($statusPage, $request->validated('components'));
            }
        });
        Cache::forget('status:'.$oldSlug);
        Cache::forget('status:'.$statusPage->slug);

        return new StatusPageResource($statusPage->load('monitors:id'));
    }

    public function destroy(StatusPage $statusPage): Response
    {
        $statusPage->delete();
        Cache::forget('status:'.$statusPage->slug);

        return response()->noContent();
    }

    private function sync(StatusPage $page, array $components): void
    {
        $pivot = [];
        foreach ($components as $position => $component) {
            $pivot[$component['monitor_id']] = ['name' => $component['name'], 'position' => $position];
        }
        $page->monitors()->sync($pivot);
    }
}
