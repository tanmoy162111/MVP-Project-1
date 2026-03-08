<?php

namespace App\Modules\Reporting\DTOs;

final class TrendPointDTO
{
    public function __construct(
        public readonly string $period,   // "2026-03-01" | "2026-W09" | "2026-03" | "2026"
        public readonly float  $revenue,
        public readonly int    $orders,
        public readonly float  $avgOrderValue,
    ) {}

    public function toArray(): array
    {
        return [
            'period'          => $this->period,
            'revenue'         => $this->revenue,
            'orders'          => $this->orders,
            'avg_order_value' => $this->avgOrderValue,
        ];
    }
}
