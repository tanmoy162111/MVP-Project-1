<?php

namespace App\Modules\Reporting\DTOs;

final class CustomerAnalyticsDTO
{
    public function __construct(
        public readonly int   $totalCustomers,
        public readonly int   $newCustomers,
        public readonly int   $returningCustomers,
        public readonly int   $activeCustomers,       // ordered in period
        public readonly float $averageLifetimeValue,
        public readonly array $tierDistribution,      // ['standard'=>N, 'silver'=>N, ...]
        public readonly array $topCustomers,          // top 10 by spend
    ) {}

    public function toArray(): array
    {
        return [
            'total_customers'        => $this->totalCustomers,
            'new_customers'          => $this->newCustomers,
            'returning_customers'    => $this->returningCustomers,
            'active_customers'       => $this->activeCustomers,
            'avg_lifetime_value'     => $this->averageLifetimeValue,
            'tier_distribution'      => $this->tierDistribution,
            'top_customers'          => $this->topCustomers,
        ];
    }
}
