<?php

namespace App\Modules\Reporting\DTOs;

final class CategoryBreakdownDTO
{
    public function __construct(
        public readonly int    $categoryId,
        public readonly string $categoryName,
        public readonly int    $totalOrders,
        public readonly float  $revenue,
        public readonly float  $revenueShare,  // % of total revenue
        public readonly int    $unitsSold,
        public readonly int    $activeProducts,
    ) {}

    public function toArray(): array
    {
        return [
            'category_id'     => $this->categoryId,
            'category_name'   => $this->categoryName,
            'total_orders'    => $this->totalOrders,
            'revenue'         => $this->revenue,
            'revenue_share'   => $this->revenueShare,
            'units_sold'      => $this->unitsSold,
            'active_products' => $this->activeProducts,
        ];
    }
}
