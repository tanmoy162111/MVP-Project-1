<?php

namespace App\Modules\Reporting\DTOs;

final class DateRange
{
    public function __construct(
        public readonly \Carbon\Carbon $from,
        public readonly \Carbon\Carbon $to,
        public readonly string         $granularity, // day | week | month | year
    ) {}

    public static function fromRequest(string $from, string $to, string $granularity = 'day'): self
    {
        return new self(
            \Carbon\Carbon::parse($from)->startOfDay(),
            \Carbon\Carbon::parse($to)->endOfDay(),
            $granularity,
        );
    }

    public function label(): string
    {
        return $this->from->toDateString() . ' – ' . $this->to->toDateString();
    }
}
