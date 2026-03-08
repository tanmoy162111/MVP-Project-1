<?php

/**
 * Reporting Configuration
 */

return [

    // Cache TTL for report queries in seconds (default 10 minutes).
    // Set to 0 to disable caching during development.
    'cache_ttl' => env('REPORTING_CACHE_TTL', 600),

    // Inventory threshold below which a product variant is flagged as "low stock".
    'low_stock_threshold' => env('REPORTING_LOW_STOCK_THRESHOLD', 10),

    // Maximum rows allowed in a single export to prevent memory issues.
    'max_export_rows' => env('REPORTING_MAX_EXPORT_ROWS', 10000),

];
