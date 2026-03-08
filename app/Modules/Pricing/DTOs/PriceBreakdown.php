<?php

namespace App\Modules\Pricing\DTOs;

/**
 * PriceBreakdown DTO
 *
 * Immutable value object returned by PricingEngine::calculate().
 * Carries the full price calculation trace so the frontend can show
 * a transparent breakdown and the audit log can store it verbatim.
 */
final class PriceBreakdown
{
    public function __construct(
        public readonly float  $opisPrice,          // Raw OPIS feed price (0.0 if no OPIS feed configured)
        public readonly float  $basePrice,          // Product base_price before rules
        public readonly float  $marginAmount,       // BDT amount added by margin rule
        public readonly float  $discountAmount,     // BDT amount deducted by discount/contract
        public readonly float  $vatAmount,          // BDT VAT (rate × (base + margin))
        public readonly float  $finalPrice,         // What the customer pays
        public readonly string $currency,           // BDT
        public readonly string $priceSource,        // 'opis' | 'base_price' | 'contract' | 'mock'
        public readonly array  $rulesApplied,       // [['id'=>1,'name'=>'Default Margin','value'=>15,'type'=>'margin'], ...]
        public readonly ?int   $contractId,         // Set if price came from a B2B contract
        public readonly float  $vatRate,            // e.g. 0.05 for 5%
        public readonly int    $quantity,           // Quantity used for volume-tier resolution
        public readonly bool   $fromMockFeed,       // true when OPIS credentials are not yet configured
    ) {}

    /**
     * Return as array — used for API response and audit log storage.
     */
    public function toArray(): array
    {
        return [
            'opis_price'      => $this->opisPrice,
            'base_price'      => $this->basePrice,
            'margin_amount'   => $this->marginAmount,
            'discount_amount' => $this->discountAmount,
            'vat_amount'      => $this->vatAmount,
            'final_price'     => $this->finalPrice,
            'currency'        => $this->currency,
            'price_source'    => $this->priceSource,
            'vat_rate_pct'    => round($this->vatRate * 100, 2),
            'quantity'        => $this->quantity,
            'rules_applied'   => $this->rulesApplied,
            'contract_id'     => $this->contractId,
            'from_mock_feed'  => $this->fromMockFeed,
        ];
    }

    /**
     * Convenience: just the final price as a formatted BDT string.
     */
    public function formattedFinalPrice(): string
    {
        return 'BDT ' . number_format($this->finalPrice, 2);
    }
}
