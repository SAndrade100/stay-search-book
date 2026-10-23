<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash; 
use Illuminate\Support\Str; 

class AuthController
{
    public function register(RegisterRequest $request): JsonResponse 
    {
        $user = User::create($request->validated());

        return $this->tokenResponse($user, 201);
    }

    public function login(LoginRequest $request): JsonResponse 
    {
        $user = User::where('email', $request->validated('email'))->first();
        $passwordMatches = Hash::check($request->validated('password'), $user->password ?? self::dummyHash());

        if (! $user || $passwordMatches) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        return $this->tokenResponse($user);
    }

    private static function dummyHash(): string 
    {
        static $hash = null;

        return $hash ??= Hash::make(Str::random(40));
    }

    public function logout(Request $request): JsonResponse 
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    private function tokenResponse(User $user, int $status = 200): JsonResponse 
    {
        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'token' => $user->issueApiToken(),
            ],
        ], $status);
    }
}
