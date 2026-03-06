<?php

namespace Database\Factories;

use App\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name'              => fake()->name(),
            'email'             => fake()->unique()->safeEmail(),
            'phone'             => fake()->phoneNumber(),
            'email_verified_at' => now(),
            'password'          => bcrypt('Password1!'),
            'type'              => 'customer',
            'status'            => 'active',
            'customer_tier'     => 'standard',
            'credit_limit'      => 0,
            'credit_used'       => 0,
            'remember_token'    => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(['type' => 'admin', 'status' => 'active']);
    }

    public function vendor(): static
    {
        return $this->state(['type' => 'vendor', 'status' => 'active']);
    }

    public function suspended(): static
    {
        return $this->state(['status' => 'suspended']);
    }

    public function corporate(): static
    {
        return $this->state(['customer_tier' => 'corporate', 'credit_limit' => 500000]);
    }
}
