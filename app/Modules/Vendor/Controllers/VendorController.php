<?php

namespace App\Modules\Vendor\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Vendor\Models\Vendor;
use App\Modules\Vendor\Services\VendorService;
use App\Modules\Vendor\Requests\RegisterVendorRequest;
use App\Modules\Vendor\Requests\UpdateVendorRequest;
use App\Modules\Vendor\Requests\ApprovalActionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VendorController extends Controller
{
    public function __construct(private VendorService $vendorService) {}

    /**
     * GET /api/v1/vendors
     * Admin: all vendors with filters. Vendor: own profile only.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Vendor::with('user:id,name,email,phone')
            ->when($request->status, fn($q, $v) => $q->where('status', $v))
            ->when($request->search, fn($q, $v) =>
                $q->where('store_name', 'like', "%{$v}%")
                  ->orWhere('email', 'like', "%{$v}%")
            )
            ->latest();

        return $this->paginated($query->paginate($request->integer('per_page', 15)));
    }

    /**
     * POST /api/v1/vendors/register
     * Authenticated user registers as vendor.
     */
    public function register(RegisterVendorRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->vendor) {
            return $this->badRequest('You already have a vendor profile.');
        }

        $vendor = $this->vendorService->register($user, $request->validated());

        return $this->created($this->formatVendor($vendor),
            'Vendor profile submitted. Pending admin approval.');
    }

    /**
     * GET /api/v1/vendors/{id}
     */
    public function show(int $id): JsonResponse
    {
        $vendor = Vendor::with(['user:id,name,email', 'products' => fn($q) => $q->where('status', 'active')->limit(10)])
            ->findOrFail($id);

        return $this->success($this->formatVendor($vendor));
    }

    /**
     * PUT /api/v1/vendors/{id}
     * Vendor updates own profile. Admin can update any.
     */
    public function update(UpdateVendorRequest $request, int $id): JsonResponse
    {
        $vendor = Vendor::findOrFail($id);
        $user   = $request->user();

        // Vendor can only edit own profile; admin can edit any
        if (! $user->hasRole(['admin', 'super_admin']) && $vendor->user_id !== $user->id) {
            return $this->forbidden('You can only edit your own vendor profile.');
        }

        $vendor = $this->vendorService->update($vendor, $request->validated());

        return $this->success($this->formatVendor($vendor), 'Vendor profile updated.');
    }

    /**
     * POST /api/v1/vendors/{id}/approve
     * Admin only.
     */
    public function approve(int $id, Request $request): JsonResponse
    {
        $vendor = Vendor::findOrFail($id);

        if ($vendor->isActive()) {
            return $this->badRequest('Vendor is already active.');
        }

        $vendor = $this->vendorService->approve($vendor, $request->user());

        return $this->success($this->formatVendor($vendor), 'Vendor approved successfully.');
    }

    /**
     * POST /api/v1/vendors/{id}/reject
     */
    public function reject(ApprovalActionRequest $request, int $id): JsonResponse
    {
        $vendor = Vendor::findOrFail($id);
        $vendor = $this->vendorService->reject($vendor, $request->validated('reason'));

        return $this->success($this->formatVendor($vendor), 'Vendor rejected.');
    }

    /**
     * POST /api/v1/vendors/{id}/suspend
     */
    public function suspend(ApprovalActionRequest $request, int $id): JsonResponse
    {
        $vendor = Vendor::findOrFail($id);
        $vendor = $this->vendorService->suspend($vendor, $request->validated('reason'));

        return $this->success($this->formatVendor($vendor), 'Vendor suspended.');
    }

    // ── PRIVATE ───────────────────────────────────────────────────────────────

    private function formatVendor(Vendor $vendor): array
    {
        return [
            'id'              => $vendor->id,
            'store_name'      => $vendor->store_name,
            'slug'            => $vendor->slug,
            'description'     => $vendor->description,
            'logo'            => $vendor->logo,
            'banner'          => $vendor->banner,
            'email'           => $vendor->email,
            'phone'           => $vendor->phone,
            'address'         => $vendor->address,
            'commission_rate' => $vendor->commission_rate,
            'status'          => $vendor->status,
            'rejection_reason'=> $vendor->rejection_reason,
            'average_rating'  => $vendor->average_rating,
            'rating_count'    => $vendor->rating_count,
            'total_sales'     => $vendor->total_sales,
            'approved_at'     => $vendor->approved_at,
            'owner'           => $vendor->relationLoaded('user') ? [
                'id'    => $vendor->user->id,
                'name'  => $vendor->user->name,
                'email' => $vendor->user->email,
            ] : null,
        ];
    }
}
