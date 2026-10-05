<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\OrganizationAccess;
use App\Http\Requests\NotificationRequest;
use App\Http\Requests\PageRequest;
use App\Http\Resources\ChannelResource;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Services\Quota;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

class NotificationController extends Controller
{
    public function index(PageRequest $request): AnonymousResourceCollection
    {
        return ChannelResource::collection(NotificationChannel::where('organization_id', OrganizationAccess::organization($request)->id)->latest('id')->paginate($request->pageSize()));
    }

    public function store(NotificationRequest $request, Quota $quota): ChannelResource
    {
        $organization = OrganizationAccess::organization($request);

        return new ChannelResource($quota->create($organization, 'channels', fn () => NotificationChannel::create([...$request->validated(), 'organization_id' => $organization->id]))->refresh());
    }

    public function update(NotificationRequest $request, NotificationChannel $channel): ChannelResource
    {
        $channel->update($request->validated());

        return new ChannelResource($channel);
    }

    public function destroy(NotificationChannel $channel): Response
    {
        $channel->delete();

        return response()->noContent();
    }

    public function deliveries(PageRequest $request): AnonymousResourceCollection
    {
        return JsonResource::collection(NotificationDelivery::where('organization_id', OrganizationAccess::organization($request)->id)->latest('id')->paginate($request->pageSize()));
    }

    public function attempts(PageRequest $request, NotificationDelivery $delivery): AnonymousResourceCollection
    {
        return JsonResource::collection($delivery->deliveryAttempts()->orderBy('attempt')->paginate($request->pageSize()));
    }
}
