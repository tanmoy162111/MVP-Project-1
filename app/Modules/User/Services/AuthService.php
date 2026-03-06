<?php

namespace App\Modules\User\Services;

use App\Modules\User\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class AuthService
{
    /**
     * Register a new customer account.
     * Wraps in transaction — if role assignment fails, user is not created.
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name'     => $data['name'],
                'email'    => $data['email'],
                'phone'    => $data['phone'] ?? null,
                'password' => $data['password'],
                'type'     => 'customer',
                'status'   => 'active',
                'customer_tier' => 'standard',
                'credit_limit'  => 0,
                'credit_used'   => 0,
            ]);

            $user->assignRole('customer');

            $token = $user->createToken('api')->plainTextToken;

            return [
                'user'  => $this->formatUser($user),
                'token' => $token,
            ];
        });
    }

    /**
     * Authenticate user and return token.
     * Returns null if credentials are invalid or account is not active.
     */
    public function login(string $email, string $password, string $deviceName = 'api'): ?array
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            return null;
        }

        if (! $user->isActive()) {
            return null;
        }

        // Update last login timestamp
        $user->update(['last_login_at' => now()]);

        $token = $user->createToken($deviceName)->plainTextToken;

        return [
            'user'  => $this->formatUser($user->fresh()->load('roles')),
            'token' => $token,
        ];
    }

    /**
     * Delete current token and issue a new one.
     */
    public function rotateToken(User $user, string $deviceName = 'api'): string
    {
        $user->currentAccessToken()->delete();

        return $user->createToken($deviceName)->plainTextToken;
    }

    private function formatUser(User $user): array
    {
        return [
            'id'            => $user->id,
            'name'          => $user->name,
            'email'         => $user->email,
            'phone'         => $user->phone,
            'type'          => $user->type,
            'status'        => $user->status,
            'customer_tier' => $user->customer_tier,
            'roles'         => $user->getRoleNames(),
        ];
    }
}
