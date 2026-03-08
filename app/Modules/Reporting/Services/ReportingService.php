<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\DTOs\DateRange;
use App\Modules\Reporting\DTOs\SalesSummaryDTO;
use App\Modules\Reporting\DTOs\TrendPointDTO;
use App\Modules\Reporting\DTOs\VendorPerformanceDTO;
use App\Modules\Reporting\DTOs\ProductAnalyticsDTO;
use App\Modules\Reporting\DTOs\CategoryBreakdownDTO;
use App\Modules\Reporting\DTOs\CustomerAnalyticsDTO;
use App\Modules\Reporting\DTOs\DashboardSnapshotDTO;
use App\Modules\Order\Models\Order;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\CRM\Models\VendorPayout;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * ReportingService
 *
 * All queries are read-only aggregations.
 * Results are cached for a configurable TTL (default 10 minutes)
 * so repeated dashboard loads don't hammer the DB.
 */
class ReportingService
{
    private int $cacheTtl; // seconds

    public function __construct()
    {
        $this->cacheTtl = (int) config('reporting.cache_ttl', 600);
    }

    // ── SALES SUMMARY ─────────────────────────────────────────────────────────

    /**
     * Aggregate sales figures for a period.
     */
    public function salesSummary(DateRange $range): SalesSummaryDTO
    {
        $cacheKey = "report:sales_summary:{$range->from->toDateString()}:{$range->to->toDateString()}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($range) {
            $base = Order::whereBetween('created_at', [$range->from, $range->to]);

            $totals = (clone $base)
                ->selectRaw('
                    COUNT(*) as total_orders,
                    COUNT(CASE WHEN status = ? THEN 1 END) as completed_orders,
                    COUNT(CASE WHEN status = ? THEN 1 END) as cancelled_orders,
                    COALESCE(SUM(total_amount), 0) as gross_revenue,
                    COALESCE(SUM(discount_amount), 0) as discount_total,
                    COALESCE(SUM(tax_amount), 0) as tax_total,
                    COALESCE(SUM(freight_cost), 0) as freight_total,
                    COUNT(DISTINCT customer_id) as unique_customers
                ', [Order::STATUS_DELIVERED, Order::STATUS_CANCELLED])
                ->first();

            $newCustomers = User::where('type', 'customer')
                ->whereBetween('created_at', [$range->from, $range->to])
                ->count();

            $commissionEarned = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->join('vendors', 'vendors.id', '=', 'order_items.vendor_id')
                ->whereBetween('orders.created_at', [$range->from, $range->to])
                ->where('orders.status', Order::STATUS_DELIVERED)
                ->selectRaw('COALESCE(SUM(order_items.total_price * vendors.commission_rate / 100), 0) as commission')
                ->value('commission');

            $vendorPayouts = VendorPayout::whereBetween('created_at', [$range->from, $range->to])
                ->where('status', VendorPayout::STATUS_COMPLETED)
                ->sum('net_amount');

            $gross   = (float) $totals->gross_revenue;
            $disc    = (float) $totals->discount_total;
            $orders  = (int)   $totals->total_orders;
            $net     = $gross - $disc;
            $aov     = $orders > 0 ? round($gross / $orders, 2) : 0;

            return new SalesSummaryDTO(
                periodLabel:      $range->label(),
                totalOrders:      $orders,
                completedOrders:  (int) $totals->completed_orders,
                cancelledOrders:  (int) $totals->cancelled_orders,
                grossRevenue:     $gross,
                discountTotal:    $disc,
                taxTotal:         (float) $totals->tax_total,
                freightTotal:     (float) $totals->freight_total,
                netRevenue:       $net,
                averageOrderValue:$aov,
                uniqueCustomers:  (int) $totals->unique_customers,
                newCustomers:     $newCustomers,
                commissionEarned: (float) $commissionEarned,
                vendorPayouts:    (float) $vendorPayouts,
            );
        });
    }

    // ── REVENUE TRENDS ────────────────────────────────────────────────────────

    /**
     * Returns an array of TrendPointDTOs bucketed by granularity.
     *
     * @return TrendPointDTO[]
     */
    public function revenueTrend(DateRange $range): array
    {
        $cacheKey = "report:trend:{$range->granularity}:{$range->from->toDateString()}:{$range->to->toDateString()}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($range) {
            $groupExpr = match ($range->granularity) {
                'week'  => "DATE_FORMAT(created_at, '%Y-W%u')",
                'month' => "DATE_FORMAT(created_at, '%Y-%m')",
                'year'  => "YEAR(created_at)",
                default => "DATE(created_at)",           // day
            };

            $rows = Order::whereBetween('created_at', [$range->from, $range->to])
                ->selectRaw("
                    {$groupExpr} as period,
                    COUNT(*) as orders,
                    COALESCE(SUM(total_amount), 0) as revenue
                ")
                ->groupByRaw($groupExpr)
                ->orderByRaw($groupExpr)
                ->get();

            return $rows->map(fn($r) => new TrendPointDTO(
                period:        (string) $r->period,
                revenue:       (float)  $r->revenue,
                orders:        (int)    $r->orders,
                avgOrderValue: $r->orders > 0 ? round((float) $r->revenue / (int) $r->orders, 2) : 0,
            ))->values()->all();
        });
    }

    // ── VENDOR PERFORMANCE ────────────────────────────────────────────────────

    /**
     * @return VendorPerformanceDTO[]
     */
    public function vendorPerformance(DateRange $range, int $limit = 20): array
    {
        $cacheKey = "report:vendors:{$range->from->toDateString()}:{$range->to->toDateString()}:{$limit}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($range, $limit) {
            $rows = DB::table('order_items')
                ->join('orders',  'orders.id',  '=', 'order_items.order_id')
                ->join('vendors', 'vendors.id', '=', 'order_items.vendor_id')
                ->whereBetween('orders.created_at', [$range->from, $range->to])
                ->whereNotIn('orders.status', [Order::STATUS_CANCELLED, Order::STATUS_REFUNDED])
                ->selectRaw('
                    vendors.id as vendor_id,
                    vendors.store_name,
                    vendors.commission_rate,
                    COUNT(DISTINCT orders.id) as total_orders,
                    COALESCE(SUM(order_items.total_price), 0) as gross_sales,
                    COUNT(DISTINCT order_items.product_id) as unique_products,
                    COUNT(DISTINCT orders.customer_id) as unique_customers
                ')
                ->groupBy('vendors.id', 'vendors.store_name', 'vendors.commission_rate')
                ->orderByDesc('gross_sales')
                ->limit($limit)
                ->get();

            return $rows->values()->map(function ($r, $i) {
                $gross      = (float) $r->gross_sales;
                $commission = round($gross * ((float) $r->commission_rate) / 100, 2);
                $orders     = (int) $r->total_orders;

                return new VendorPerformanceDTO(
                    vendorId:         (int) $r->vendor_id,
                    storeName:        $r->store_name,
                    totalOrders:      $orders,
                    grossSales:       $gross,
                    commissionRate:   (float) $r->commission_rate,
                    commissionAmount: $commission,
                    netPayout:        round($gross - $commission, 2),
                    uniqueProducts:   (int) $r->unique_products,
                    uniqueCustomers:  (int) $r->unique_customers,
                    averageOrderValue:$orders > 0 ? round($gross / $orders, 2) : 0,
                    rank:             $i + 1,
                );
            })->all();
        });
    }

    // ── PRODUCT ANALYTICS ─────────────────────────────────────────────────────

    /**
     * @return ProductAnalyticsDTO[]
     */
    public function topProducts(DateRange $range, int $limit = 20, ?int $categoryId = null, ?int $vendorId = null): array
    {
        $cacheKey = "report:products:{$range->from->toDateString()}:{$range->to->toDateString()}:{$limit}:{$categoryId}:{$vendorId}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($range, $limit, $categoryId, $vendorId) {
            $rows = DB::table('order_items')
                ->join('orders',     'orders.id',     '=', 'order_items.order_id')
                ->join('products',   'products.id',   '=', 'order_items.product_id')
                ->join('vendors',    'vendors.id',    '=', 'order_items.vendor_id')
                ->join('categories', 'categories.id', '=', 'products.category_id')
                ->join('product_variants', function($join) {
                    $join->on('product_variants.id', '=', 'order_items.variant_id')
                         ->orWhereNull('order_items.variant_id');
                })
                ->whereBetween('orders.created_at', [$range->from, $range->to])
                ->whereNotIn('orders.status', [Order::STATUS_CANCELLED, Order::STATUS_REFUNDED])
                ->when($categoryId, fn($q) => $q->where('products.category_id', $categoryId))
                ->when($vendorId,   fn($q) => $q->where('order_items.vendor_id', $vendorId))
                ->selectRaw('
                    products.id as product_id,
                    products.name as product_name,
                    products.sku,
                    products.view_count,
                    vendors.store_name as vendor_name,
                    categories.name as category_name,
                    COALESCE(SUM(order_items.quantity), 0) as units_sold,
                    COALESCE(SUM(order_items.total_price), 0) as revenue
                ')
                ->groupBy('products.id', 'products.name', 'products.sku', 'products.view_count', 'vendors.store_name', 'categories.name')
                ->orderByDesc('revenue')
                ->limit($limit)
                ->get();

            // Get stock for these products
            $productIds = $rows->pluck('product_id');
            $stocks     = DB::table('product_variants')
                ->whereIn('product_id', $productIds)
                ->selectRaw('product_id, SUM(stock_quantity) as total_stock')
                ->groupBy('product_id')
                ->pluck('total_stock', 'product_id');

            $lowStockThreshold = config('reporting.low_stock_threshold', 10);

            return $rows->values()->map(function ($r, $i) use ($stocks, $lowStockThreshold) {
                $units    = (int)   $r->units_sold;
                $views    = (int)   $r->view_count;
                $stock    = (int)  ($stocks[$r->product_id] ?? 0);
                $conv     = $views > 0 ? round(($units / $views) * 100, 2) : 0;

                return new ProductAnalyticsDTO(
                    productId:      (int) $r->product_id,
                    productName:    $r->product_name,
                    sku:            $r->sku,
                    vendorName:     $r->vendor_name,
                    categoryName:   $r->category_name,
                    unitsSold:      $units,
                    revenue:        (float) $r->revenue,
                    viewCount:      $views,
                    conversionRate: $conv,
                    stockQuantity:  $stock,
                    isLowStock:     $stock <= $lowStockThreshold,
                    rank:           $i + 1,
                );
            })->all();
        });
    }

    /**
     * Products with stock at or below the low-stock threshold.
     * Used for the inventory alert widget.
     */
    public function lowStockProducts(int $threshold = null, int $limit = 50): array
    {
        $threshold = $threshold ?? config('reporting.low_stock_threshold', 10);

        return DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->join('vendors',  'vendors.id',  '=', 'products.vendor_id')
            ->where('product_variants.is_active', true)
            ->where('products.status', 'active')
            ->where('product_variants.stock_quantity', '<=', $threshold)
            ->selectRaw('
                products.id as product_id,
                products.name as product_name,
                products.sku as product_sku,
                vendors.store_name as vendor_name,
                product_variants.id as variant_id,
                product_variants.name as variant_name,
                product_variants.sku as variant_sku,
                product_variants.stock_quantity,
                product_variants.low_stock_threshold
            ')
            ->orderBy('product_variants.stock_quantity')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    // ── CATEGORY BREAKDOWN ────────────────────────────────────────────────────

    /**
     * @return CategoryBreakdownDTO[]
     */
    public function categoryBreakdown(DateRange $range): array
    {
        $cacheKey = "report:categories:{$range->from->toDateString()}:{$range->to->toDateString()}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($range) {
            $rows = DB::table('order_items')
                ->join('orders',     'orders.id',     '=', 'order_items.order_id')
                ->join('products',   'products.id',   '=', 'order_items.product_id')
                ->join('categories', 'categories.id', '=', 'products.category_id')
                ->whereBetween('orders.created_at', [$range->from, $range->to])
                ->whereNotIn('orders.status', [Order::STATUS_CANCELLED, Order::STATUS_REFUNDED])
                ->selectRaw('
                    categories.id as category_id,
                    categories.name as category_name,
                    COUNT(DISTINCT orders.id) as total_orders,
                    COALESCE(SUM(order_items.total_price), 0) as revenue,
                    COALESCE(SUM(order_items.quantity), 0) as units_sold
                ')
                ->groupBy('categories.id', 'categories.name')
                ->orderByDesc('revenue')
                ->get();

            $totalRevenue = $rows->sum('revenue');

            // Active product counts per category
            $productCounts = DB::table('products')
                ->where('status', 'active')
                ->selectRaw('category_id, COUNT(*) as cnt')
                ->groupBy('category_id')
                ->pluck('cnt', 'category_id');

            return $rows->values()->map(fn($r) => new CategoryBreakdownDTO(
                categoryId:    (int)  $r->category_id,
                categoryName:  $r->category_name,
                totalOrders:   (int)  $r->total_orders,
                revenue:       (float) $r->revenue,
                revenueShare:  $totalRevenue > 0 ? round(($r->revenue / $totalRevenue) * 100, 2) : 0,
                unitsSold:     (int)  $r->units_sold,
                activeProducts:(int) ($productCounts[$r->category_id] ?? 0),
            ))->all();
        });
    }

    // ── CUSTOMER ANALYTICS ────────────────────────────────────────────────────

    public function customerAnalytics(DateRange $range): CustomerAnalyticsDTO
    {
        $cacheKey = "report:customers:{$range->from->toDateString()}:{$range->to->toDateString()}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($range) {
            $totalCustomers = User::where('type', 'customer')->count();

            $newCustomers = User::where('type', 'customer')
                ->whereBetween('created_at', [$range->from, $range->to])
                ->count();

            // Customers who ordered in period
            $activeInPeriod = Order::whereBetween('created_at', [$range->from, $range->to])
                ->distinct('customer_id')
                ->count('customer_id');

            // Returning = ordered before AND in period
            $returningCustomers = Order::whereBetween('created_at', [$range->from, $range->to])
                ->whereIn('customer_id', function ($sub) use ($range) {
                    $sub->select('customer_id')
                        ->from('orders')
                        ->where('created_at', '<', $range->from);
                })
                ->distinct('customer_id')
                ->count('customer_id');

            // Average lifetime value
            $avgLtv = DB::table('orders')
                ->where('status', Order::STATUS_DELIVERED)
                ->selectRaw('AVG(customer_total) as avg_ltv')
                ->fromSub(
                    DB::table('orders')
                        ->where('status', Order::STATUS_DELIVERED)
                        ->selectRaw('customer_id, SUM(total_amount) as customer_total')
                        ->groupBy('customer_id'),
                    'customer_totals'
                )
                ->value('avg_ltv') ?? 0;

            // Tier distribution
            $tierDist = User::where('type', 'customer')
                ->selectRaw('COALESCE(customer_tier, "standard") as customer_tier, COUNT(*) as cnt')
                ->groupBy('customer_tier')
                ->pluck('cnt', 'customer_tier')
                ->toArray();

            // Top 10 customers by spend in period
            $topCustomers = DB::table('orders')
                ->join('users', 'users.id', '=', 'orders.customer_id')
                ->whereBetween('orders.created_at', [$range->from, $range->to])
                ->where('orders.status', Order::STATUS_DELIVERED)
                ->selectRaw('
                    users.id,
                    users.name,
                    users.company_name,
                    users.tier,
                    COUNT(orders.id) as order_count,
                    SUM(orders.total_amount) as total_spend
                ')
                ->groupBy('users.id', 'users.name', 'users.company_name', 'users.tier')
                ->orderByDesc('total_spend')
                ->limit(10)
                ->get()
                ->toArray();

            return new CustomerAnalyticsDTO(
                totalCustomers:      $totalCustomers,
                newCustomers:        $newCustomers,
                returningCustomers:  $returningCustomers,
                activeCustomers:     $activeInPeriod,
                averageLifetimeValue:(float) $avgLtv,
                tierDistribution:    $tierDist,
                topCustomers:        array_map(fn($c) => (array) $c, $topCustomers),
            );
        });
    }

    // ── DASHBOARD SNAPSHOT ────────────────────────────────────────────────────

    /**
     * Single-call KPI snapshot for the admin dashboard.
     * Cached for a shorter TTL (default 2 min) since it shows today's data.
     */
    public function dashboardSnapshot(): DashboardSnapshotDTO
    {
        return Cache::remember('report:dashboard', 120, function () {
            $today     = now()->startOfDay();
            $monthStart= now()->startOfMonth();
            $yearStart = now()->startOfYear();

            $revenueToday = Order::where('created_at', '>=', $today)
                ->where('status', '!=', Order::STATUS_CANCELLED)
                ->sum('total_amount');

            $revenueMtd = Order::where('created_at', '>=', $monthStart)
                ->where('status', '!=', Order::STATUS_CANCELLED)
                ->sum('total_amount');

            $revenueYtd = Order::where('created_at', '>=', $yearStart)
                ->where('status', '!=', Order::STATUS_CANCELLED)
                ->sum('total_amount');

            $ordersToday = Order::where('created_at', '>=', $today)->count();

            $ordersPending = Order::where('status', Order::STATUS_PENDING)->count();

            $ordersShipping = Order::whereIn('status', [
                Order::STATUS_CONFIRMED, Order::STATUS_PROCESSING
            ])->count();

            $activeProducts = DB::table('products')->where('status', 'active')->count();
            $activeVendors  = DB::table('vendors')->where('status', 'active')->count();

            $lowStock = DB::table('product_variants')
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->where('products.status', 'active')
                ->where('product_variants.is_active', true)
                ->whereRaw('product_variants.stock_quantity <= COALESCE(product_variants.low_stock_threshold, ?)', [
                    config('reporting.low_stock_threshold', 10)
                ])
                ->count();

            $overdueInvoices = Invoice::overdue()->count();
            $unpaidValue     = Invoice::unpaid()->sum('balance_due');

            $pendingPayouts = VendorPayout::where('status', VendorPayout::STATUS_PENDING)->count();
            $pendingPayoutValue = VendorPayout::where('status', VendorPayout::STATUS_PENDING)->sum('net_amount');

            $recentOrders = Order::with('customer:id,name,company_name')
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn($o) => [
                    'id'           => $o->id,
                    'order_number' => $o->order_number,
                    'customer'     => $o->customer->name ?? '—',
                    'total'        => $o->total_amount,
                    'status'       => $o->status,
                    'created_at'   => $o->created_at,
                ])->toArray();

            // Last 7 days trend (one row per day)
            $trend7d = Order::where('created_at', '>=', now()->subDays(6)->startOfDay())
                ->where('status', '!=', Order::STATUS_CANCELLED)
                ->selectRaw('DATE(created_at) as day, COUNT(*) as orders, SUM(total_amount) as revenue')
                ->groupByRaw('DATE(created_at)')
                ->orderBy('day')
                ->get()
                ->map(fn($r) => ['day' => $r->day, 'orders' => (int) $r->orders, 'revenue' => (float) $r->revenue])
                ->toArray();

            return new DashboardSnapshotDTO(
                revenueToday:           (float) $revenueToday,
                revenueMtd:             (float) $revenueMtd,
                revenueYtd:             (float) $revenueYtd,
                ordersToday:            (int)   $ordersToday,
                ordersPending:          (int)   $ordersPending,
                ordersAwaitingShipment: (int)   $ordersShipping,
                totalActiveProducts:    (int)   $activeProducts,
                totalActiveVendors:     (int)   $activeVendors,
                lowStockProducts:       (int)   $lowStock,
                overdueInvoices:        (int)   $overdueInvoices,
                unpaidInvoiceValue:     (float) $unpaidValue,
                pendingVendorPayouts:   (int)   $pendingPayouts,
                pendingPayoutValue:     (float) $pendingPayoutValue,
                recentOrders:           $recentOrders,
                revenueTrend7d:         $trend7d,
            );
        });
    }

    /**
     * Bust the dashboard cache — call after any write that affects KPIs.
     */
    public function bustDashboardCache(): void
    {
        Cache::forget('report:dashboard');
    }
}
