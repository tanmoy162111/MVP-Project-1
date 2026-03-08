<?php

namespace App\Modules\Reporting\DTOs;

final class SalesSummaryDTO
{
    public function __construct(
        public readonly string $periodLabel,
        public readonly int    $totalOrders,
        public readonly int    $completedOrders,
        public readonly int    $cancelledOrders,
        public readonly float  $grossRevenue,
        public readonly float  $discountTotal,
        public readonly float  $taxTotal,
        public readonly float  $freightTotal,
        public readonly float  $netRevenue,       // gross − discount − refunds
        public readonly float  $averageOrderValue,
        public readonly int    $uniqueCustomers,
        public readonly int    $newCustomers,
        public readonly float  $commissionEarned,  // platform's take
        public readonly float  $vendorPayouts,     // net paid to vendors
    ) {}

    public function toArray(): array
    {
        return [
            'period'             => $this->periodLabel,
            'total_orders'       => $this->totalOrders,
            'completed_orders'   => $this->completedOrders,
            'cancelled_orders'   => $this->cancelledOrders,
            'gross_revenue'      => $this->grossRevenue,
            'discount_total'     => $this->discountTotal,
            'tax_total'          => $this->taxTotal,
            'freight_total'      => $this->freightTotal,
            'net_revenue'        => $this->netRevenue,
            'average_order_value'=> $this->averageOrderValue,
            'unique_customers'   => $this->uniqueCustomers,
            'new_customers'      => $this->newCustomers,
            'commission_earned'  => $this->commissionEarned,
            'vendor_payouts'     => $this->vendorPayouts,
        ];
    }
}
