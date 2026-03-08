<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\VendorPayout;
use App\Modules\Vendor\Models\Vendor;
use App\Modules\User\Models\User;
use App\Modules\Order\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * VendorPayoutService
 *
 * Calculates what each vendor is owed for a given period,
 * creates a payout record, and marks it as processed once payment is sent.
 *
 * Formula:
 *   gross_sales      = SUM(order_items.total_price) for DELIVERED items in period
 *   commission_amount= gross_sales × vendor.commission_rate / 100
 *   net_amount       = gross_sales − commission_amount
 */
class VendorPayoutService
{
    /**
     * Preview payout for a vendor without creating a DB record.
     * Used in the admin UI "Calculate" step before approving.
     */
    public function preview(Vendor $vendor, string $periodFrom, string $periodTo): array
    {
        $items = $this->getEligibleItems($vendor->id, $periodFrom, $periodTo);

        if ($items->isEmpty()) {
            return [
                'vendor_id'         => $vendor->id,
                'vendor_name'       => $vendor->store_name,
                'period_from'       => $periodFrom,
                'period_to'         => $periodTo,
                'gross_sales'       => 0,
                'commission_rate'   => $vendor->commission_rate,
                'commission_amount' => 0,
                'net_amount'        => 0,
                'item_count'        => 0,
                'order_item_ids'    => [],
                'already_paid'      => false,
            ];
        }

        $grossSales      = $items->sum('total_price');
        $commissionRate  = (float) $vendor->commission_rate;
        $commissionAmt   = round($grossSales * $commissionRate / 100, 2);
        $netAmount       = round($grossSales - $commissionAmt, 2);

        $alreadyPaid = VendorPayout::where('vendor_id', $vendor->id)
            ->whereDate('period_from', '<=', $periodTo)
            ->whereDate('period_to',   '>=', $periodFrom)
            ->whereIn('status', [VendorPayout::STATUS_PROCESSING, VendorPayout::STATUS_COMPLETED])
            ->exists();

        return [
            'vendor_id'         => $vendor->id,
            'vendor_name'       => $vendor->store_name,
            'period_from'       => $periodFrom,
            'period_to'         => $periodTo,
            'gross_sales'       => $grossSales,
            'commission_rate'   => $commissionRate,
            'commission_amount' => $commissionAmt,
            'net_amount'        => $netAmount,
            'item_count'        => $items->count(),
            'order_item_ids'    => $items->pluck('id')->toArray(),
            'already_paid'      => $alreadyPaid,
        ];
    }

    /**
     * Create a pending payout record for a vendor.
     * Admin reviews the preview first, then calls this to lock it in.
     */
    public function create(Vendor $vendor, string $periodFrom, string $periodTo, User $admin): VendorPayout
    {
        $preview = $this->preview($vendor, $periodFrom, $periodTo);

        if ($preview['net_amount'] <= 0) {
            throw new \RuntimeException("No payable amount for vendor {$vendor->store_name} in the selected period.");
        }

        if ($preview['already_paid']) {
            throw new \RuntimeException("A payout already exists for this vendor and period.");
        }

        return DB::transaction(function () use ($vendor, $periodFrom, $periodTo, $admin, $preview) {
            $payout = VendorPayout::create([
                'vendor_id'         => $vendor->id,
                'period_from'       => $periodFrom,
                'period_to'         => $periodTo,
                'gross_sales'       => $preview['gross_sales'],
                'commission_amount' => $preview['commission_amount'],
                'net_amount'        => $preview['net_amount'],
                'currency'          => 'BDT',
                'status'            => VendorPayout::STATUS_PENDING,
                'order_item_ids'    => $preview['order_item_ids'],
                'notes'             => "Created by {$admin->name}",
                'approved_by'       => $admin->id,
            ]);

            // Mark included order items so they aren't double-counted
            OrderItem::whereIn('id', $preview['order_item_ids'])
                ->update(['payout_id' => $payout->id]);

            Log::info("VendorPayout #{$payout->id} created for vendor {$vendor->id}: BDT {$preview['net_amount']}");

            return $payout;
        });
    }

    /**
     * Mark a payout as processing (payment initiated externally).
     */
    public function markProcessing(VendorPayout $payout, string $transactionRef, User $admin): VendorPayout
    {
        if (! $payout->isPending()) {
            throw new \RuntimeException("Only pending payouts can be moved to processing.");
        }

        $payout->update([
            'status'          => VendorPayout::STATUS_PROCESSING,
            'transaction_ref' => $transactionRef,
            'approved_by'     => $admin->id,
        ]);

        return $payout->fresh();
    }

    /**
     * Mark a payout as completed (payment confirmed).
     */
    public function complete(VendorPayout $payout): VendorPayout
    {
        $payout->update([
            'status'       => VendorPayout::STATUS_COMPLETED,
            'processed_at' => now(),
        ]);

        // Update vendor's total_revenue
        Vendor::where('id', $payout->vendor_id)
            ->increment('total_revenue', $payout->net_amount);

        Log::info("VendorPayout #{$payout->id} completed. Vendor #{$payout->vendor_id} paid BDT {$payout->net_amount}.");

        return $payout->fresh();
    }

    /**
     * Bulk preview for all active vendors in a period.
     * Used on the admin payouts dashboard.
     */
    public function previewAll(string $periodFrom, string $periodTo): array
    {
        return Vendor::where('status', 'active')
            ->get()
            ->map(fn($v) => $this->preview($v, $periodFrom, $periodTo))
            ->filter(fn($p) => $p['net_amount'] > 0)
            ->values()
            ->toArray();
    }

    // ── PRIVATE ───────────────────────────────────────────────────────────────

    private function getEligibleItems(int $vendorId, string $periodFrom, string $periodTo)
    {
        return OrderItem::where('vendor_id', $vendorId)
            ->whereNull('payout_id')   // Not already in a payout
            ->whereHas('order', fn($q) =>
                $q->where('status', \App\Modules\Order\Models\Order::STATUS_DELIVERED)
                  ->whereBetween('delivered_at', [$periodFrom . ' 00:00:00', $periodTo . ' 23:59:59'])
            )
            ->get();
    }
}
