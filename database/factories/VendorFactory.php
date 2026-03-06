<?php

namespace Database\Factories;

use App\Modules\Vendor\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    public function definition(): array
    {
        $name = fake()->company();
        return [
            'store_name'      => $name,
            'slug'            => Str::slug($name),
            'description'     => fake()->sentence(),
            'phone'           => fake()->phoneNumber(),
            'email'           => fake()->companyEmail(),
            'address'         => fake()->address(),
            'commission_rate' => 10.00,
            'status'          => 'active',
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => 'pending']);
    }

    public function suspended(): static
    {
        return $this->state(['status' => 'suspended']);
    }
}
