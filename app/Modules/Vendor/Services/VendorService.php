<?php

namespace App\Modules\Vendor\Services;

use App\Modules\Vendor\Models\Vendor;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VendorService
{
    /**
     * Register a new vendor profile for an existing user.
     * Sets user type to 'vendor' and assigns the vendor role.
     */
    public function register(User $user, array $data): Vendor
    {
        return DB::transaction(function () use ($user, $data) {
            $vendor = Vendor::create([
                'user_id'      => $user->id,
                'store_name'   => $data['store_name'],
                'slug'         => $this->generateSlug($data['store_name']),
                'description'  => $data['description'] ?? null,
                'phone'        => $data['phone'] ?? null,
                'email'        => $data['email'] ?? $user->email,
                'address'      => $data['address'] ?? null,
                'trade_license'=> $data['trade_license'] ?? null,
                'tin_number'   => $data['tin_number'] ?? null,
                'bin_number'   => $data['bin_number'] ?? null,
                'bank_name'    => $data['bank_name'] ?? null,
                'bank_account_number'  => $data['bank_account_number'] ?? null,
                'bank_routing_number'  => $data['bank_routing_number'] ?? null,
                'status'       => 'pending',
                'commission_rate' => 10.00, // default — admin can change
            ]);

            // Update user type
            $user->update(['type' => 'vendor']);
            $user->syncRoles(['vendor']);

            return $vendor;
        });
    }

    /**
     * Admin approves a vendor.
     */
    public function approve(Vendor $vendor, User $approvedBy): Vendor
    {
        $vendor->update([
            'status'      => 'active',
            'approved_at' => now(),
            'approved_by' => $approvedBy->id,
            'rejection_reason' => null,
        ]);

        return $vendor->fresh();
    }

    /**
     * Admin rejects a vendor with a reason.
     */
    public function reject(Vendor $vendor, string $reason): Vendor
    {
        $vendor->update([
            'status'           => 'rejected',
            'rejection_reason' => $reason,
        ]);

        return $vendor->fresh();
    }

    /**
     * Admin suspends a vendor.
     */
    public function suspend(Vendor $vendor, string $reason): Vendor
    {
        $vendor->update([
            'status'           => 'suspended',
            'rejection_reason' => $reason,
        ]);

        return $vendor->fresh();
    }

    /**
     * Update vendor profile (vendor self-service).
     */
    public function update(Vendor $vendor, array $data): Vendor
    {
        // Slug only regenerated if store name changes
        if (isset($data['store_name']) && $data['store_name'] !== $vendor->store_name) {
            $data['slug'] = $this->generateSlug($data['store_name']);
        }

        $vendor->update($data);

        return $vendor->fresh();
    }

    private function generateSlug(string $name): string
    {
        $slug = Str::slug($name);
        $count = Vendor::where('slug', 'like', "{$slug}%")->count();

        return $count > 0 ? "{$slug}-{$count}" : $slug;
    }
}
