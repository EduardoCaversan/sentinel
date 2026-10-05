<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AuthRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(AuthRequest $request): JsonResponse
    {
        abort_unless(config('sentinel.registration_enabled'), 403, 'Registration is disabled.');

        return $this->token(User::create($request->safe()->only(['name', 'email', 'password'])), 201);
    }

    public function login(AuthRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();
        if (! Hash::check($request->validated('password'), $user->password ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.')) {
            abort(401, 'Invalid credentials.');
        }
        abort_unless($user !== null, 401, 'Invalid credentials.');

        return $this->token($user);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    private function token(User $user, int $status = 200): JsonResponse
    {
        $user->personalOrganization();
        $expires = now()->addDays(7);

        return response()->json(['data' => ['user' => new UserResource($user), 'token' => $user->createToken('api', ['*'], $expires)->plainTextToken, 'token_type' => 'Bearer', 'expires_at' => $expires->toISOString()]], $status)->header('Cache-Control', 'no-store');
    }
}
