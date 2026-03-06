<?php

namespace App\Modules\Order\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderService;
use App\Modules\Order\Services\OrderStateMachine;
use App\Modules\Order\Requests\PlaceOrderRequest;
use App\Modules\Order\Requests\TransitionOrderRequest;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\CreditLimitExceededException;
use App\Exceptions\InvalidOrderTransitionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        private OrderService      $orderService,
        private OrderStateMachine $stateMachine
    ) {}

    /**
     * GET /api/v1/orders
     * Customer: own orders. Admin/order_manager: all orders.
     */
    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = Order::with(['items.product:id,name', 'items.vendor:id,store_name'])
            ->when(! $user->hasRole(['admin', 'super_admin', 'order_manager']),
                fn($q) => $q->where('customer_id', $user->id)
            )
            ->when($request->status, fn($q, $v) => $q->where('status', $v))
            ->when($request->payment_status, fn($q, $v) => $q->where('payment_status', $v))
            ->when($request->from, fn($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($request->to,   fn($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest();

        return $this->paginated($query->paginate($request->integer('per_page', 15)));
    }

    /**
     * POST /api/v1/orders
     * Customer places a new order.
     */
    public function store(PlaceOrderRequest $request): JsonResponse
    {
        try {
            $order = $this->orderService->placeOrder($request->user(), $request->validated());

            return $this->created($this->formatOrder($order, detailed: true), 'Order placed successfully.');

        } catch (InsufficientStockException $e) {
            return $this->badRequest($e->getMessage());

        } catch (CreditLimitExceededException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code'    => 'CREDIT_LIMIT_EXCEEDED',
            ], 422);
        }
    }

    /**
     * GET /api/v1/orders/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user  = $request->user();
        $order = Order::with([
            'items.product:id,name,slug',
            'items.variant:id,name,attributes',
            'items.vendor:id,store_name',
            'statusHistory.changedBy:id,name',
            'customer:id,name,email,phone',
        ])->findOrFail($id);

        // Customers can only see own orders
        if (! $user->hasRole(['admin', 'super_admin', 'order_manager']) && $order->customer_id !== $user->id) {
            return $this->forbidden();
        }

        return $this->success($this->formatOrder($order, detailed: true));
    }

    /**
     * POST /api/v1/orders/{id}/transition
     * Admin/order_manager transitions order to a new status.
     */
    public function transition(TransitionOrderRequest $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        try {
            $order = $this->orderService->transition(
                $order,
                $request->validated('status'),
                $request->user(),
                $request->validated('note')
            );

            return $this->success($this->formatOrder($order), "Order moved to [{$order->status}].");

        } catch (InvalidOrderTransitionException $e) {
            return $this->badRequest($e->getMessage());
        }
    }

    /**
     * POST /api/v1/orders/{id}/cancel
     * Customer can cancel only if order is still pending.
     * Admin can cancel at more stages.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $request->validate(['reason' => 'required|string|min:5']);

        $order = Order::findOrFail($id);
        $user  = $request->user();

        // Customer ownership check
        if (! $user->hasRole(['admin', 'super_admin', 'order_manager']) && $order->customer_id !== $user->id) {
            return $this->forbidden();
        }

        // Customer can only cancel while pending
        if (! $user->hasRole(['admin', 'super_admin', 'order_manager']) && ! $order->isPending()) {
            return $this->badRequest('You can only cancel orders that are still pending. Please contact support.');
        }

        try {
            $order = $this->orderService->cancel($order, $user, $request->reason);
            return $this->success($this->formatOrder($order), 'Order cancelled.');
        } catch (InvalidOrderTransitionException $e) {
            return $this->badRequest($e->getMessage());
        }
    }

    /**
     * GET /api/v1/orders/{id}/next-statuses
     * Returns valid next transitions — used by admin UI dropdown.
     */
    public function nextStatuses(int $id): JsonResponse
    {
        $order = Order::findOrFail($id);
        return $this->success([
            'current_status' => $order->status,
            'next_statuses'  => $this->stateMachine->nextStatuses($order->status),
        ]);
    }

    // ── PRIVATE ───────────────────────────────────────────────────────────────

    private function formatOrder(Order $order, bool $detailed = false): array
    {
        $base = [
            'id'             => $order->id,
            'order_number'   => $order->order_number,
            'status'         => $order->status,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'subtotal'       => $order->subtotal,
            'freight_cost'   => $order->freight_cost,
            'tax_amount'     => $order->tax_amount,
            'discount_amount'=> $order->discount_amount,
            'total_amount'   => $order->total_amount,
            'created_at'     => $order->created_at,
        ];

        if (! $detailed) {
            return $base;
        }

        return array_merge($base, [
            'shipping_address'   => $order->shipping_address,
            'delivery_notes'     => $order->delivery_notes,
            'cancellation_reason'=> $order->cancellation_reason,
            'approved_at'        => $order->approved_at,
            'delivered_at'       => $order->delivered_at,
            'customer' => $order->relationLoaded('customer') ? [
                'id'    => $order->customer->id,
                'name'  => $order->customer->name,
                'email' => $order->customer->email,
                'phone' => $order->customer->phone,
            ] : null,
            'items' => $order->relationLoaded('items')
                ? $order->items->map(fn($i) => [
                    'id'           => $i->id,
                    'product_name' => $i->product_name,
                    'variant_name' => $i->variant_name,
                    'sku'          => $i->sku,
                    'quantity'     => $i->quantity,
                    'unit_price'   => $i->unit_price,
                    'total_price'  => $i->total_price,
                    'status'       => $i->status,
                    'vendor'       => $i->relationLoaded('vendor') ? ['name' => $i->vendor->store_name] : null,
                ]) : [],
            'status_history' => $order->relationLoaded('statusHistory')
                ? $order->statusHistory->map(fn($h) => [
                    'from'       => $h->from_status,
                    'to'         => $h->to_status,
                    'note'       => $h->note,
                    'changed_by' => $h->changedBy?->name,
                    'at'         => $h->created_at,
                ]) : [],
            'next_statuses' => $this->stateMachine->nextStatuses($order->status),
        ]);
    }
}
