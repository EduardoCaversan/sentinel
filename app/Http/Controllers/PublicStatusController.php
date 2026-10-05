<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StatusPage;
use App\Services\PublicStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class PublicStatusController extends Controller
{
    private function data(string $slug, PublicStatus $status): array
    {
        // Publication is checked even on a warm cache, so unpublishing is immediate.
        $page = StatusPage::where('slug', $slug)->where('is_published', true)->firstOrFail();

        return Cache::remember('status:'.$slug, 30, fn () => $status->data($page));
    }

    public function show(string $slug, PublicStatus $status): JsonResponse
    {
        return response()->json(['data' => $this->data($slug, $status)]);
    }

    public function page(string $slug, PublicStatus $status): View
    {
        return view('status', ['page' => $this->data($slug, $status)]);
    }
}
