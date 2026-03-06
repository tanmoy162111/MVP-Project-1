<?php

namespace App\Modules\User\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\User\Models\User;
use App\Modules\User\Requests\LoginRequest;
use App\Modules\User\Requests\RegisterRequest;
use App\Modules\User\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private AuthService $authService) {}

    /**
     * POST /api/v1/auth/register
     * Public — customer self-registration.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register($request->validated());

        return $this->created($result, 'Account created successfully. Please verify your email.');
    }

    /**
     * POST /api/v1/auth/login
     * Returns Sanctum token on success.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login(
            $request->validated('email'),
            $request->validated('password'),
            $request->input('device_name', 'api')
        );

        if (! $result) {
            return $this->badRequest('Invalid credentials. Please check your email and password.');
        }

        return $this->success($result, 'Login successful.');
    }

    /**
     * POST /api/v1/auth/logout
     * Revokes current token.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        
        if ($token) {
            $token->delete();
        }

        return $this->noContent();
    }

    /**
     * GET /api/v1/auth/me
     * Returns authenticated user profile with roles and permissions.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles');

        return $this->success([
            'id'            => $user->id,
            'name'          => $user->name,
            'email'         => $user->email,
            'phone'         => $user->phone,
            'type'          => $user->type,
            'status'        => $user->status,
            'customer_tier' => $user->customer_tier,
            'roles'         => $user->getRoleNames(),
            'permissions'   => $user->getAllPermissions()->pluck('name'),
            'last_login_at' => $user->last_login_at,
        ]);
    }

    /**
     * POST /api/v1/auth/refresh
     * Rotates the current token.
     */
    public function refresh(Request $request): JsonResponse
    {
        $user  = $request->user();
        $token = $this->authService->rotateToken($user, $request->input('device_name', 'api'));

        return $this->success(['token' => $token], 'Token refreshed.');
    }
}
