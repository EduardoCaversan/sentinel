<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\OrganizationAccess;
use App\Http\Requests\MaintenanceRequest;
use App\Http\Requests\PageRequest;
use App\Http\Resources\MaintenanceResource;
use App\Models\MaintenanceWindow;
use App\Services\Quota;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class MaintenanceController extends Controller
{
    public function index(PageRequest $request): AnonymousResourceCollection
    {
        return MaintenanceResource::collection(MaintenanceWindow::where('organization_id', OrganizationAccess::organization($request)->id)->with('monitors:id')->latest('id')->paginate($request->pageSize()));
    }

    public function store(MaintenanceRequest $request, Quota $quota): MaintenanceResource
    {
        $organization = OrganizationAccess::organization($request);
        $window = $quota->create($organization, 'maintenance', function () use ($organization, $request): MaintenanceWindow {
            $window = MaintenanceWindow::create([...$request->safe()->except('monitor_ids'), 'organization_id' => $organization->id]);
            $window->monitors()->sync($request->validated('monitor_ids'));

            return $window;
        });

        return new MaintenanceResource($window->load('monitors:id'));
    }

    public function destroy(MaintenanceWindow $maintenance): Response
    {
        abort_unless($maintenance->start_at->isFuture(), 409, 'Only future maintenance can be cancelled; historical windows are immutable.');
        $maintenance->delete();

        return response()->noContent();
    }
}
