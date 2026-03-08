<?php

namespace App\Modules\Reporting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Services\ReportingService;
use App\Modules\Reporting\Exports\ExportService;
use App\Modules\Reporting\DTOs\DateRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ReportingController
 *
 * All endpoints require admin|super_admin|finance_manager roles.
 * pricing_manager can access the product/category reports.
 * Vendors can access their own vendor performance slice via /vendor/reports.
 */
class ReportingController extends Controller
{
    public function __construct(
        private ReportingService $reporting,
        private ExportService    $exportService,
    ) {}

    // ── DASHBOARD ─────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/admin/reports/dashboard
     * Single-call KPI snapshot for the admin home page.
     * Cached for 2 minutes.
     */
    public function dashboard(): JsonResponse
    {
        return $this->success(
            $this->reporting->dashboardSnapshot()->toArray(),
            'Dashboard snapshot.'
        );
    }

    // ── SALES SUMMARY ─────────────────────────────────────────────────────────

    /**
     * GET /api/v1/admin/reports/sales
     * Query params: from, to
     */
    public function salesSummary(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $range = DateRange::fromRequest($request->from, $request->to);

        return $this->success($this->reporting->salesSummary($range)->toArray());
    }

    // ── REVENUE TREND ─────────────────────────────────────────────────────────

    /**
     * GET /api/v1/admin/reports/revenue-trend
     * Query params: from, to, granularity (day|week|month|year)
     */
    public function revenueTrend(Request $request): JsonResponse
    {
        $request->validate([
            'from'        => 'required|date',
            'to'          => 'required|date|after_or_equal:from',
            'granularity' => 'nullable|in:day,week,month,year',
        ]);

        $range = DateRange::fromRequest($request->from, $request->to, $request->granularity ?? 'day');
        $trend = $this->reporting->revenueTrend($range);

        return $this->success([
            'period'      => $range->label(),
            'granularity' => $range->granularity,
            'data_points' => count($trend),
            'trend'       => array_map(fn($p) => $p->toArray(), $trend),
        ]);
    }

    // ── VENDOR PERFORMANCE ────────────────────────────────────────────────────

    /**
     * GET /api/v1/admin/reports/vendors
     * Query params: from, to, limit (default 20)
     */
    public function vendorPerformance(Request $request): JsonResponse
    {
        $request->validate([
            'from'  => 'required|date',
            'to'    => 'required|date|after_or_equal:from',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $range   = DateRange::fromRequest($request->from, $request->to);
        $vendors = $this->reporting->vendorPerformance($range, $request->integer('limit', 20));

        return $this->success([
            'period'  => $range->label(),
            'count'   => count($vendors),
            'vendors' => array_map(fn($v) => $v->toArray(), $vendors),
        ]);
    }

    /**
     * GET /api/v1/vendor/reports/performance
     * Vendor self-service: own performance stats only.
     */
    public function vendorSelfReport(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $vendor = $request->user()->vendor;
        if (! $vendor) {
            return $this->forbidden('No vendor account found.');
        }

        $range   = DateRange::fromRequest($request->from, $request->to);
        $vendors = $this->reporting->vendorPerformance($range, 100);
        $own     = collect($vendors)->firstWhere('vendorId', $vendor->id);

        return $this->success($own ? $own->toArray() : [
            'vendor_id'   => $vendor->id,
            'store_name'  => $vendor->store_name,
            'gross_sales' => 0,
            'net_payout'  => 0,
            'total_orders'=> 0,
            'message'     => 'No sales data for this period.',
        ]);
    }

    // ── TOP PRODUCTS ──────────────────────────────────────────────────────────

    /**
     * GET /api/v1/admin/reports/products
     * Query params: from, to, limit, category_id, vendor_id
     */
    public function topProducts(Request $request): JsonResponse
    {
        $request->validate([
            'from'        => 'required|date',
            'to'          => 'required|date|after_or_equal:from',
            'limit'       => 'nullable|integer|min:1|max:100',
            'category_id' => 'nullable|integer|exists:categories,id',
            'vendor_id'   => 'nullable|integer|exists:vendors,id',
        ]);

        $range    = DateRange::fromRequest($request->from, $request->to);
        $products = $this->reporting->topProducts(
            $range,
            $request->integer('limit', 20),
            $request->integer('category_id') ?: null,
            $request->integer('vendor_id')   ?: null,
        );

        return $this->success([
            'period'   => $range->label(),
            'count'    => count($products),
            'products' => array_map(fn($p) => $p->toArray(), $products),
        ]);
    }

    // ── LOW STOCK ALERT ───────────────────────────────────────────────────────

    /**
     * GET /api/v1/admin/reports/low-stock
     * Query params: threshold (default 10), limit (default 50)
     */
    public function lowStock(Request $request): JsonResponse
    {
        $request->validate([
            'threshold' => 'nullable|integer|min:0',
            'limit'     => 'nullable|integer|min:1|max:200',
        ]);

        $items = $this->reporting->lowStockProducts(
            $request->integer('threshold') ?: null,
            $request->integer('limit', 50)
        );

        return $this->success([
            'count'     => count($items),
            'threshold' => $request->integer('threshold', config('reporting.low_stock_threshold', 10)),
            'items'     => $items,
        ]);
    }

    // ── CATEGORY BREAKDOWN ────────────────────────────────────────────────────

    /**
     * GET /api/v1/admin/reports/categories
     * Query params: from, to
     */
    public function categoryBreakdown(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $range      = DateRange::fromRequest($request->from, $request->to);
        $categories = $this->reporting->categoryBreakdown($range);

        return $this->success([
            'period'     => $range->label(),
            'count'      => count($categories),
            'categories' => array_map(fn($c) => $c->toArray(), $categories),
        ]);
    }

    // ── CUSTOMER ANALYTICS ────────────────────────────────────────────────────

    /**
     * GET /api/v1/admin/reports/customers
     * Query params: from, to
     */
    public function customerAnalytics(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $range = DateRange::fromRequest($request->from, $request->to);

        return $this->success($this->reporting->customerAnalytics($range)->toArray());
    }

    // ── EXPORT ────────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/admin/reports/export
     * Query params: type, from, to, format (csv|xlsx), limit?, category_id?, vendor_id?
     *
     * Supported types: sales_summary | revenue_trend | vendor_performance |
     *                  top_products | category_breakdown | low_stock
     */
    public function export(Request $request)
    {
        $request->validate([
            'type'        => 'required|in:sales_summary,revenue_trend,vendor_performance,top_products,category_breakdown,low_stock',
            'from'        => 'required_unless:type,low_stock|nullable|date',
            'to'          => 'required_unless:type,low_stock|nullable|date|after_or_equal:from',
            'format'      => 'nullable|in:csv,xlsx',
            'limit'       => 'nullable|integer|min:1|max:1000',
            'category_id' => 'nullable|integer',
            'vendor_id'   => 'nullable|integer',
        ]);

        $format  = $request->input('format', 'csv');
        $filters = $request->only(['limit', 'category_id', 'vendor_id']);

        // low_stock doesn't need a date range
        $range = ($request->from && $request->to)
            ? DateRange::fromRequest($request->from, $request->to, 'day')
            : DateRange::fromRequest(now()->subYear()->toDateString(), now()->toDateString());

        try {
            if ($format === 'xlsx') {
                $path     = $this->exportService->generateXlsx($request->type, $range, $filters);
                $filename = basename($path);
                return response()->download($path, $filename, [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ])->deleteFileAfterSend(true);
            }

            // CSV stream
            return $this->exportService->streamCsv($request->type, $range, $filters);

        } catch (\InvalidArgumentException $e) {
            return $this->badRequest($e->getMessage());
        } catch (\RuntimeException $e) {
            return $this->badRequest($e->getMessage());
        }
    }
}
