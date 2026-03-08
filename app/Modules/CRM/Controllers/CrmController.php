<?php

namespace App\Modules\CRM\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CommunicationLog;
use App\Modules\CRM\Models\Coupon;
use App\Modules\CRM\Models\Notification;
use App\Modules\CRM\Models\VendorPayout;
use App\Modules\CRM\Models\Wishlist;
use App\Modules\CRM\Services\CouponService;
use App\Modules\CRM\Services\CustomerTierService;
use App\Modules\CRM\Services\VendorPayoutService;
use App\Modules\Product\Models\Product;
use App\Modules\Vendor\Models\Vendor;
use App\Modules\User\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrmController extends Controller
{
    public function __construct(
        private CustomerTierService $tierService,
        private CouponService       $couponService,
        private VendorPayoutService $payoutService,
    ) {}

    // ── CUSTOMER PROFILE & TIER ───────────────────────────────────────────────

    /**
     * GET /api/v1/account/tier
     * Customer: view own tier progress and credit info.
     */
    public function myTier(Request $request): JsonResponse
    {
        return $this->success(
            $this->tierService->getTierProgress($request->user()),
            'Tier information retrieved.'
        );
    }

    /**
     * POST /api/v1/admin/customers/{id}/evaluate-tier
     * Admin: manually re-evaluate a customer's tier.
     */
    public function evaluateTier(int $customerId): JsonResponse
    {
        $customer = User::where('type', 'customer')->findOrFail($customerId);
        $result   = $this->tierService->evaluate($customer);

        return $this->success($result, $result['changed']
            ? "Tier updated: {$result['old_tier']} → {$result['new_tier']}."
            : "Tier unchanged: {$result['new_tier']}."
        );
    }

    /**
     * POST /api/v1/admin/customers/evaluate-all-tiers
     * Admin: bulk re-evaluate all customers.
     */
    public function evaluateAllTiers(): JsonResponse
    {
        $count = $this->tierService->evaluateAll();
        return $this->success(['customers_upgraded' => $count], "{$count} customers had their tier updated.");
    }

    // ── COMMUNICATION LOG ─────────────────────────────────────────────────────

    /**
     * GET /api/v1/admin/customers/{id}/communications
     */
    public function communicationIndex(Request $request, int $customerId): JsonResponse
    {
        User::findOrFail($customerId); // 404 if not found

        $logs = CommunicationLog::with('createdBy:id,name')
            ->forCustomer($customerId)
            ->when($request->type,   fn($q, $v) => $q->where('type', $v))
            ->when($request->pinned, fn($q)     => $q->pinned())
            ->latest()
            ->paginate($request->integer('per_page', 25));

        return $this->paginated($logs);
    }

    /**
     * POST /api/v1/admin/customers/{id}/communications
     * Admin/staff manually log a call, meeting, or note.
     */
    public function communicationStore(Request $request, int $customerId): JsonResponse
    {
        User::findOrFail($customerId);

        $data = $request->validate([
            'type'         => 'required|in:email,call,note,meeting,support',
            'direction'    => 'nullable|in:inbound,outbound',
            'subject'      => 'required|string|max:200',
            'body'         => 'required|string',
            'related_type' => 'nullable|string|in:order,invoice,contract',
            'related_id'   => 'nullable|integer',
            'is_pinned'    => 'nullable|boolean',
            'metadata'     => 'nullable|array',
        ]);

        $log = CommunicationLog::create(array_merge($data, [
            'customer_id' => $customerId,
            'created_by'  => $request->user()->id,
            'direction'   => $data['direction'] ?? CommunicationLog::DIRECTION_OUTBOUND,
        ]));

        return $this->created($log->load('createdBy:id,name'), 'Communication log entry created.');
    }

    /**
     * PATCH /api/v1/admin/customers/{customerId}/communications/{logId}/pin
     * Toggle pinned status.
     */
    public function communicationTogglePin(int $customerId, int $logId): JsonResponse
    {
        $log = CommunicationLog::where('customer_id', $customerId)->findOrFail($logId);
        $log->update(['is_pinned' => ! $log->is_pinned]);

        return $this->success(['is_pinned' => $log->is_pinned],
            $log->is_pinned ? 'Log entry pinned.' : 'Log entry unpinned.');
    }

    // ── WISHLIST ──────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/account/wishlist
     */
    public function wishlistIndex(Request $request): JsonResponse
    {
        $items = Wishlist::with([
                'product:id,name,slug,base_price,status',
                'product.primaryImage',
                'variant:id,name,price_adjustment',
            ])
            ->where('customer_id', $request->user()->id)
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginated($items);
    }

    /**
     * POST /api/v1/account/wishlist
     */
    public function wishlistAdd(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'variant_id' => 'nullable|integer|exists:product_variants,id',
            'note'       => 'nullable|string|max:255',
        ]);

        $existing = Wishlist::where('customer_id', $request->user()->id)
            ->where('product_id', $data['product_id'])
            ->where('variant_id', $data['variant_id'] ?? null)
            ->first();

        if ($existing) {
            return $this->success($existing, 'Product already in wishlist.');
        }

        $item = Wishlist::create(array_merge($data, ['customer_id' => $request->user()->id]));

        return $this->created($item, 'Product added to wishlist.');
    }

    /**
     * DELETE /api/v1/account/wishlist/{id}
     */
    public function wishlistRemove(Request $request, int $id): JsonResponse
    {
        $item = Wishlist::where('customer_id', $request->user()->id)->findOrFail($id);
        $item->delete();
        return $this->noContent();
    }

    // ── NOTIFICATIONS ─────────────────────────────────────────────────────────

    /**
     * GET /api/v1/account/notifications
     */
    public function notificationIndex(Request $request): JsonResponse
    {
        $notifications = Notification::where('user_id', $request->user()->id)
            ->when($request->unread, fn($q) => $q->unread())
            ->latest()
            ->paginate($request->integer('per_page', 20));

        $unreadCount = Notification::where('user_id', $request->user()->id)->unread()->count();

        return response()->json([
            'success'      => true,
            'data'         => $notifications->items(),
            'unread_count' => $unreadCount,
            'meta'         => [
                'current_page' => $notifications->currentPage(),
                'per_page'     => $notifications->perPage(),
                'total'        => $notifications->total(),
                'last_page'    => $notifications->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/v1/account/notifications/read-all
     */
    public function notificationReadAll(Request $request): JsonResponse
    {
        Notification::where('user_id', $request->user()->id)
            ->unread()
            ->update(['is_read' => true, 'read_at' => now()]);

        return $this->success(null, 'All notifications marked as read.');
    }

    /**
     * PATCH /api/v1/account/notifications/{id}/read
     */
    public function notificationRead(Request $request, int $id): JsonResponse
    {
        $notification = Notification::where('user_id', $request->user()->id)->findOrFail($id);
        $notification->update(['is_read' => true, 'read_at' => now()]);
        return $this->success($notification);
    }

    // ── COUPONS ───────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/coupons/validate
     * Customer: validate a coupon before placing order.
     */
    public function couponValidate(Request $request): JsonResponse
    {
        $request->validate([
            'code'           => 'required|string|max:50',
            'order_subtotal' => 'required|numeric|min:0',
            'cart_items'     => 'nullable|array',
        ]);

        $result = $this->couponService->validate(
            $request->code,
            $request->user(),
            (float) $request->order_subtotal,
            $request->cart_items ?? []
        );

        $status = $result['valid'] ? 200 : 422;

        return response()->json([
            'success'  => $result['valid'],
            'message'  => $result['message'],
            'data'     => $result['valid'] ? [
                'discount'    => $result['discount'],
                'coupon_code' => $result['coupon']->code,
                'coupon_type' => $result['coupon']->type,
                'coupon_value'=> $result['coupon']->value,
            ] : null,
        ], $status);
    }

    /**
     * GET  /api/v1/admin/coupons
     * POST /api/v1/admin/coupons
     * PUT  /api/v1/admin/coupons/{id}
     * DELETE /api/v1/admin/coupons/{id}
     */
    public function couponIndex(Request $request): JsonResponse
    {
        $coupons = Coupon::withTrashed()
            ->when($request->active, fn($q) => $q->active())
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginated($coupons);
    }

    public function couponStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'                     => 'required|string|max:50|unique:coupons,code',
            'type'                     => 'required|in:percent,flat,shipping',
            'value'                    => 'required|numeric|min:0',
            'min_order_amount'         => 'nullable|numeric|min:0',
            'max_discount_amount'      => 'nullable|numeric|min:0',
            'usage_limit'              => 'nullable|integer|min:1',
            'usage_limit_per_user'     => 'nullable|integer|min:1',
            'applicable_product_ids'   => 'nullable|array',
            'applicable_category_ids'  => 'nullable|array',
            'applicable_customer_ids'  => 'nullable|array',
            'is_active'                => 'nullable|boolean',
            'starts_at'                => 'nullable|date',
            'ends_at'                  => 'nullable|date|after:starts_at',
        ]);

        $data['code']      = strtoupper($data['code']);
        $data['is_active'] = $data['is_active'] ?? true;
        $coupon            = Coupon::create($data);

        return $this->created($coupon, 'Coupon created.');
    }

    public function couponUpdate(Request $request, int $id): JsonResponse
    {
        $coupon = Coupon::findOrFail($id);
        $data   = $request->validate([
            'value'              => 'sometimes|numeric|min:0',
            'min_order_amount'   => 'nullable|numeric|min:0',
            'max_discount_amount'=> 'nullable|numeric|min:0',
            'usage_limit'        => 'nullable|integer|min:1',
            'is_active'          => 'sometimes|boolean',
            'starts_at'          => 'nullable|date',
            'ends_at'            => 'nullable|date',
        ]);

        $coupon->update($data);
        return $this->success($coupon->fresh(), 'Coupon updated.');
    }

    public function couponDestroy(int $id): JsonResponse
    {
        Coupon::findOrFail($id)->delete();
        return $this->noContent();
    }

    // ── VENDOR PAYOUTS ────────────────────────────────────────────────────────

    /**
     * GET /api/v1/admin/payouts/preview
     * Preview payout amounts for a period without committing.
     */
    public function payoutPreviewAll(Request $request): JsonResponse
    {
        $request->validate([
            'period_from' => 'required|date',
            'period_to'   => 'required|date|after_or_equal:period_from',
        ]);

        $previews = $this->payoutService->previewAll($request->period_from, $request->period_to);

        return $this->success([
            'period_from'  => $request->period_from,
            'period_to'    => $request->period_to,
            'vendors'      => $previews,
            'total_payout' => collect($previews)->sum('net_amount'),
        ]);
    }

    /**
     * POST /api/v1/admin/payouts
     * Create a payout for a specific vendor.
     */
    public function payoutCreate(Request $request): JsonResponse
    {
        $request->validate([
            'vendor_id'   => 'required|integer|exists:vendors,id',
            'period_from' => 'required|date',
            'period_to'   => 'required|date|after_or_equal:period_from',
        ]);

        $vendor = Vendor::findOrFail($request->vendor_id);

        try {
            $payout = $this->payoutService->create($vendor, $request->period_from, $request->period_to, $request->user());
            return $this->created($payout->load('vendor:id,store_name'), 'Payout record created.');
        } catch (\RuntimeException $e) {
            return $this->badRequest($e->getMessage());
        }
    }

    /**
     * GET /api/v1/admin/payouts
     */
    public function payoutIndex(Request $request): JsonResponse
    {
        $payouts = VendorPayout::with('vendor:id,store_name,email')
            ->when($request->vendor_id, fn($q, $v) => $q->where('vendor_id', $v))
            ->when($request->status,    fn($q, $v) => $q->where('status', $v))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginated($payouts);
    }

    /**
     * POST /api/v1/admin/payouts/{id}/process
     * Mark payout as processing with a transaction reference.
     */
    public function payoutProcess(Request $request, int $id): JsonResponse
    {
        $request->validate(['transaction_ref' => 'required|string|max:100']);
        $payout = VendorPayout::findOrFail($id);

        try {
            $payout = $this->payoutService->markProcessing($payout, $request->transaction_ref, $request->user());
            return $this->success($payout, 'Payout marked as processing.');
        } catch (\RuntimeException $e) {
            return $this->badRequest($e->getMessage());
        }
    }

    /**
     * POST /api/v1/admin/payouts/{id}/complete
     * Mark payout as completed.
     */
    public function payoutComplete(int $id): JsonResponse
    {
        $payout = VendorPayout::findOrFail($id);
        $payout = $this->payoutService->complete($payout);
        return $this->success($payout, 'Payout completed. Vendor revenue updated.');
    }

    /**
     * GET /api/v1/vendor/payouts
     * Vendor: view own payout history.
     */
    public function vendorPayoutHistory(Request $request): JsonResponse
    {
        $vendor = $request->user()->vendor;

        if (! $vendor) {
            return $this->forbidden('No vendor account found.');
        }

        $payouts = VendorPayout::where('vendor_id', $vendor->id)
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->paginated($payouts);
    }
}
