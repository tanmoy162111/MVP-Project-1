<?php

namespace App\Modules\CRM\Services;

use App\Modules\User\Models\User;
use App\Modules\Order\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CustomerTierService
 *
 * Evaluates a customer's tier (bronze / silver / gold / platinum) based on
 * their rolling 12-month spend and order count, then syncs their credit limit.
 *
 * Tier thresholds (all PLACEHOLDER — confirm with client):
 * ┌───────────┬─────────────────────┬──────────────┬──────────────────┐
 * │ Tier      │ 12-month spend (BDT)│ Min orders   │ Credit limit     │
 * ├───────────┼─────────────────────┼──────────────┼──────────────────┤
 * │ bronze    │ < 1,00,000          │ any          │ 0 (no credit)    │
 * │ silver    │ 1,00,000 – 4,99,999 │ ≥ 3          │ 50,000           │
 * │ gold      │ 5,00,000 – 19,99,999│ ≥ 10         │ 2,00,000         │
 * │ platinum  │ ≥ 20,00,000         │ ≥ 20         │ 5,00,000         │
 * └───────────┴─────────────────────┴──────────────┴──────────────────┘
 *
 * All thresholds are in config/crm.php (PLACEHOLDER values — replace before go-live).
 */
class CustomerTierService
{
    private array $tiers;

    public function __construct()
    {
        $this->tiers = config('crm.tiers', $this->defaultTiers());
    }

    /**
     * Evaluate and update a single customer's tier.
     * Logs the change if tier changed.
     * Returns ['old_tier', 'new_tier', 'changed'].
     */
    public function evaluate(User $customer): array
    {
        $stats   = $this->getStats($customer->id);
        $newTier = $this->resolveTier($stats['spend_12m'], $stats['order_count_12m']);
        $oldTier = $customer->customer_tier ?? 'standard';

        if ($newTier === $oldTier) {
            return ['old_tier' => $oldTier, 'new_tier' => $newTier, 'changed' => false, 'stats' => $stats];
        }

        $newCreditLimit = $this->tiers[$newTier]['credit_limit'];

        DB::transaction(function () use ($customer, $newTier, $newCreditLimit) {
            $customer->update([
                'customer_tier' => $newTier,
                'credit_limit'  => $newCreditLimit,
            ]);

            // Log the tier change as a communication log entry
            \App\Modules\CRM\Models\CommunicationLog::create([
                'customer_id' => $customer->id,
                'created_by'  => null,
                'type'        => \App\Modules\CRM\Models\CommunicationLog::TYPE_SYSTEM,
                'direction'   => \App\Modules\CRM\Models\CommunicationLog::DIRECTION_OUTBOUND,
                'subject'     => "Tier upgraded to {$newTier}",
                'body'        => "Customer automatically moved from tier [{$customer->tier}] to [{$newTier}] based on rolling 12-month spend.",
                'metadata'    => ['old_tier' => $customer->tier, 'new_tier' => $newTier, 'new_credit_limit' => $newCreditLimit],
            ]);

            // Fire notification
            \App\Modules\CRM\Models\Notification::create([
                'user_id'      => $customer->id,
                'type'         => \App\Modules\CRM\Models\Notification::TYPE_SYSTEM,
                'title'        => "Your account has been upgraded to " . ucfirst($newTier) . "!",
                'body'         => "Congratulations! Based on your purchase history, your account tier has been upgraded. You now have a credit limit of BDT " . number_format($newCreditLimit, 2) . ".",
                'action_url'   => '/account/tier',
                'related_type' => 'tier_upgrade',
                'related_id'   => $customer->id,
            ]);
        });

        Log::info("Customer #{$customer->id} tier changed: {$oldTier} → {$newTier}");

        return ['old_tier' => $oldTier, 'new_tier' => $newTier, 'changed' => true, 'stats' => $stats];
    }

    /**
     * Bulk-evaluate all customers. Run via scheduled command.
     * Returns count of customers whose tier changed.
     */
    public function evaluateAll(): int
    {
        $changed = 0;

        User::where('type', 'customer')
            ->where('status', 'active')
            ->chunkById(100, function ($customers) use (&$changed) {
                foreach ($customers as $customer) {
                    $result = $this->evaluate($customer);
                    if ($result['changed']) {
                        $changed++;
                    }
                }
            });

        Log::info("CustomerTierService: evaluated all customers. {$changed} tier changes.");

        return $changed;
    }

    /**
     * Get tier info and next tier progress for a customer (for profile display).
     */
    public function getTierProgress(User $customer): array
    {
        $stats      = $this->getStats($customer->id);
        $currentTier= $customer->customer_tier ?? 'standard';
        $nextTier   = $this->nextTier($currentTier);

        $progress = null;
        if ($nextTier) {
            $nextThreshold = $this->tiers[$nextTier]['min_spend'];
            $pct           = $nextThreshold > 0
                ? min(100, round(($stats['spend_12m'] / $nextThreshold) * 100, 1))
                : 100;

            $progress = [
                'next_tier'              => $nextTier,
                'required_spend'         => $nextThreshold,
                'current_spend'          => $stats['spend_12m'],
                'remaining_spend'        => max(0, $nextThreshold - $stats['spend_12m']),
                'percent_complete'       => $pct,
            ];
        }

        return [
            'current_tier'   => $currentTier,
            'credit_limit'   => $this->tiers[$currentTier]['credit_limit'],
            'credit_used'    => $customer->credit_used,
            'available_credit' => $customer->availableCredit(),
            'stats'          => $stats,
            'next_tier_progress' => $progress,
            'all_tiers'      => $this->tiers,
        ];
    }

    // ── PRIVATE ───────────────────────────────────────────────────────────────

    private function getStats(int $customerId): array
    {
        $since = now()->subYear();

        $result = Order::where('customer_id', $customerId)
            ->whereIn('status', [
                Order::STATUS_CONFIRMED, Order::STATUS_PROCESSING,
                Order::STATUS_SHIPPED,   Order::STATUS_DELIVERED,
            ])
            ->where('created_at', '>=', $since)
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(total_amount), 0) as total_spend')
            ->first();

        return [
            'spend_12m'       => (float) ($result->total_spend ?? 0),
            'order_count_12m' => (int)   ($result->order_count ?? 0),
        ];
    }

    private function resolveTier(float $spend, int $orderCount): string
    {
        // Evaluate tiers from highest to lowest
        foreach (['corporate', 'gold', 'silver'] as $tier) {
            $t = $this->tiers[$tier];
            if ($spend >= $t['min_spend'] && $orderCount >= $t['min_orders']) {
                return $tier;
            }
        }
        return 'standard';
    }

    private function nextTier(string $currentTier): ?string
    {
        return match ($currentTier) {
            'standard' => 'silver',
            'silver'   => 'gold',
            'gold'     => 'corporate',
            'corporate'=> null,
            default    => 'silver',
        };
    }

    private function defaultTiers(): array
    {
        // PLACEHOLDER values — override in config/crm.php
        return [
            'standard'  => ['min_spend' => 0,          'min_orders' => 0,  'credit_limit' => 0],
            'silver'    => ['min_spend' => 100000,      'min_orders' => 3,  'credit_limit' => 50000],
            'gold'      => ['min_spend' => 500000,      'min_orders' => 10, 'credit_limit' => 200000],
            'corporate' => ['min_spend' => 2000000,     'min_orders' => 20, 'credit_limit' => 500000],
        ];
    }
}
