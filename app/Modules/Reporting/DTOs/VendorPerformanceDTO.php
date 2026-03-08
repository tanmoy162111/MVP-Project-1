<?php

namespace App\Modules\Reporting\DTOs;

final class VendorPerformanceDTO
{
    public function __construct(
        public readonly int    $vendorId,
        public readonly string $storeName,
        public readonly int    $totalOrders,
        public readonly float  $grossSales,
        public readonly float  $commissionRate,
        public readonly float  $commissionAmount,
        public readonly float  $netPayout,
        public readonly int    $uniqueProducts,
        public readonly int    $uniqueCustomers,
        public readonly float  $averageOrderValue,
        public readonly int    $rank,
    ) {}

    public function toArray(): array
    {
        return [
            'rank'              => $this->rank,
            'vendor_id'         => $this->vendorId,
            'store_name'        => $this->storeName,
            'total_orders'      => $this->totalOrders,
            'gross_sales'       => $this->grossSales,
            'commission_rate'   => $this->commissionRate,
            'commission_amount' => $this->commissionAmount,
            'net_payout'        => $this->netPayout,
            'unique_products'   => $this->uniqueProducts,
            'unique_customers'  => $this->uniqueCustomers,
            'avg_order_value'   => $this->averageOrderValue,
        ];
    }
}
