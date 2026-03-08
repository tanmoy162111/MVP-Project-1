<?php

namespace App\Modules\Reporting\DTOs;

final class DashboardSnapshotDTO
{
    public function __construct(
        public readonly float  $revenueToday,
        public readonly float  $revenueMtd,       // month-to-date
        public readonly float  $revenueYtd,       // year-to-date
        public readonly int    $ordersToday,
        public readonly int    $ordersPending,
        public readonly int    $ordersAwaitingShipment,
        public readonly int    $totalActiveProducts,
        public readonly int    $totalActiveVendors,
        public readonly int    $lowStockProducts,
        public readonly int    $overdueInvoices,
        public readonly float  $unpaidInvoiceValue,
        public readonly int    $pendingVendorPayouts,
        public readonly float  $pendingPayoutValue,
        public readonly array  $recentOrders,      // last 5
        public readonly array  $revenueTrend7d,    // daily for last 7 days
    ) {}

    public function toArray(): array
    {
        return [
            'revenue' => [
                'today' => $this->revenueToday,
                'mtd'   => $this->revenueMtd,
                'ytd'   => $this->revenueYtd,
            ],
            'orders' => [
                'today'              => $this->ordersToday,
                'pending'            => $this->ordersPending,
                'awaiting_shipment'  => $this->ordersAwaitingShipment,
            ],
            'products' => [
                'active'    => $this->totalActiveProducts,
                'low_stock' => $this->lowStockProducts,
            ],
            'vendors' => [
                'active' => $this->totalActiveVendors,
            ],
            'invoices' => [
                'overdue'       => $this->overdueInvoices,
                'unpaid_value'  => $this->unpaidInvoiceValue,
            ],
            'payouts' => [
                'pending_count' => $this->pendingVendorPayouts,
                'pending_value' => $this->pendingPayoutValue,
            ],
            'recent_orders'   => $this->recentOrders,
            'revenue_trend_7d'=> $this->revenueTrend7d,
        ];
    }
}
