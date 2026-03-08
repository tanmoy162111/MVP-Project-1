<?php

namespace App\Modules\Reporting\DTOs;

final class ProductAnalyticsDTO
{
    public function __construct(
        public readonly int    $productId,
        public readonly string $productName,
        public readonly string $sku,
        public readonly string $vendorName,
        public readonly string $categoryName,
        public readonly int    $unitsSold,
        public readonly float  $revenue,
        public readonly int    $viewCount,
        public readonly float  $conversionRate,  // (unitsSold / viewCount) × 100
        public readonly int    $stockQuantity,
        public readonly bool   $isLowStock,
        public readonly int    $rank,
    ) {}

    public function toArray(): array
    {
        return [
            'rank'            => $this->rank,
            'product_id'      => $this->productId,
            'product_name'    => $this->productName,
            'sku'             => $this->sku,
            'vendor_name'     => $this->vendorName,
            'category_name'   => $this->categoryName,
            'units_sold'      => $this->unitsSold,
            'revenue'         => $this->revenue,
            'view_count'      => $this->viewCount,
            'conversion_rate' => $this->conversionRate,
            'stock_quantity'  => $this->stockQuantity,
            'is_low_stock'    => $this->isLowStock,
        ];
    }
}
