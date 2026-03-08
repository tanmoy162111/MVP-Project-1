<?php

/**
 * CRM Configuration
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║  PLACEHOLDER VALUES — Confirm with client before going live             ║
 * ╠══════════════════════════════════════════════════════════════════════════╣
 * ║  All tier thresholds (spend amounts, order counts, credit limits)       ║
 * ║  are placeholder estimates. Confirm exact values with Tanmoy.           ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */

return [

    /*
     * Customer tier definitions.
     * min_spend   : rolling 12-month spend in BDT to qualify  ← PLACEHOLDER
     * min_orders  : rolling 12-month order count to qualify   ← PLACEHOLDER
     * credit_limit: credit limit granted on reaching tier     ← PLACEHOLDER
     */
    'tiers' => [
        'bronze' => [
            'min_spend'    => 0,
            'min_orders'   => 0,
            'credit_limit' => 0,          // No credit for new customers
        ],
        'silver' => [
            'min_spend'    => env('TIER_SILVER_MIN_SPEND',    100000),   // BDT 1,00,000  ← PLACEHOLDER
            'min_orders'   => env('TIER_SILVER_MIN_ORDERS',   3),        // ← PLACEHOLDER
            'credit_limit' => env('TIER_SILVER_CREDIT_LIMIT', 50000),    // BDT 50,000    ← PLACEHOLDER
        ],
        'gold' => [
            'min_spend'    => env('TIER_GOLD_MIN_SPEND',      500000),   // BDT 5,00,000  ← PLACEHOLDER
            'min_orders'   => env('TIER_GOLD_MIN_ORDERS',     10),       // ← PLACEHOLDER
            'credit_limit' => env('TIER_GOLD_CREDIT_LIMIT',   200000),   // BDT 2,00,000  ← PLACEHOLDER
        ],
        'platinum' => [
            'min_spend'    => env('TIER_PLATINUM_MIN_SPEND',    2000000), // BDT 20,00,000 ← PLACEHOLDER
            'min_orders'   => env('TIER_PLATINUM_MIN_ORDERS',   20),      // ← PLACEHOLDER
            'credit_limit' => env('TIER_PLATINUM_CREDIT_LIMIT', 500000),  // BDT 5,00,000  ← PLACEHOLDER
        ],
    ],

    // How often to auto-evaluate tiers (cron expression)
    'tier_evaluation_schedule' => env('CRM_TIER_EVAL_SCHEDULE', 'daily'), // daily | weekly

    // Communication log retention in days
    'comm_log_retention_days' => env('CRM_COMM_LOG_RETENTION', 730), // 2 years

    // Notification retention in days before pruning read notifications
    'notification_retention_days' => env('CRM_NOTIFICATION_RETENTION', 90),

];
