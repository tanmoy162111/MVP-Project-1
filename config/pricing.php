<?php

/**
 * Pricing Engine Configuration
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║  PLACEHOLDER VALUES — Replace these in .env before going to production  ║
 * ╠══════════════════════════════════════════════════════════════════════════╣
 * ║  OPIS_API_URL              Real OPIS API base URL                       ║
 * ║  OPIS_API_KEY              Real OPIS authentication token               ║
 * ║  PRICING_DEFAULT_MARGIN_PCT  Your target margin % (default: 15)        ║
 * ║  PRICING_DEFAULT_VAT_PCT     VAT rate % (default: 5 — confirm with BD) ║
 * ║  PRICING_MIN_MARGIN_PCT      Guard rail — no rule can go below this     ║
 * ║  PRICING_MAX_MARGIN_PCT      Guard rail — no rule can exceed this       ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */

return [

    // ── OPIS FEED ─────────────────────────────────────────────────────────────
    'opis_api_url' => env('OPIS_API_URL', 'PLACEHOLDER'),   // ← PLACEHOLDER
    'opis_api_key' => env('OPIS_API_KEY', 'PLACEHOLDER'),   // ← PLACEHOLDER
    'opis_timeout'  => env('OPIS_TIMEOUT', 10),
    'opis_cache_ttl'=> env('OPIS_CACHE_TTL', 1800),         // 30 minutes

    // ── MARGIN & VAT ──────────────────────────────────────────────────────────
    'default_margin_pct' => env('PRICING_DEFAULT_MARGIN_PCT', 15.00),  // ← PLACEHOLDER
    'default_vat_pct'    => env('PRICING_DEFAULT_VAT_PCT',     5.00),  // ← PLACEHOLDER
    'min_margin_pct'     => env('PRICING_MIN_MARGIN_PCT',      5.00),  // ← PLACEHOLDER
    'max_margin_pct'     => env('PRICING_MAX_MARGIN_PCT',     40.00),  // ← PLACEHOLDER

    // ── AUDIT ─────────────────────────────────────────────────────────────────
    // Keep audit logs for this many days before pruning
    'audit_retention_days' => env('PRICING_AUDIT_RETENTION_DAYS', 90),

    // ── CACHE ────────────────────────────────────────────────────────────────
    // How long to cache resolved rule sets (avoids DB hit per product)
    'rule_cache_ttl' => env('PRICING_RULE_CACHE_TTL', 300),  // 5 minutes

];
