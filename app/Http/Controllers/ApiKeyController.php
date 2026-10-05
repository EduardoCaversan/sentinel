<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\OrganizationAccess;
use App\Http\Requests\ApiKeyRequest;
use App\Http\Requests\PageRequest;
use App\Models\OrganizationApiKey;
use App\Services\Quota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

class ApiKeyController extends Controller
{
    public function index(PageRequest $request): AnonymousResourceCollection
    {
        return JsonResource::collection(OrganizationApiKey::where('organization_id', OrganizationAccess::organization($request)->id)->latest('id')->paginate($request->pageSize()));
    }

    public function store(ApiKeyRequest $request, Quota $quota): JsonResponse
    {
        $organization = OrganizationAccess::organization($request);
        $secret = 'snl_'.bin2hex(random_bytes(32));
        $key = $quota->create($organization, 'api_keys', fn () => OrganizationApiKey::create([...$request->validated(), 'organization_id' => $organization->id, 'secret_hash' => hash('sha256', $secret)]));

        return response()->json(['data' => [...$key->refresh()->toArray(), 'secret' => $secret]], 201)->header('Cache-Control', 'no-store');
    }

    public function destroy(OrganizationApiKey $apiKey): Response
    {
        $apiKey->update(['revoked_at' => now()]);

        return response()->noContent();
    }
}
