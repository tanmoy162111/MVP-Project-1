<?php

namespace App\Modules\Order\Services;

use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderStatusHistory;
use App\Modules\User\Models\User;
use App\Exceptions\InvalidOrderTransitionException;
use Illuminate\Support\Facades\DB;

/**
 * OrderStateMachine
 *
 * Enforces the allowed state transitions for an order.
 * Any transition not listed in ALLOWED_TRANSITIONS will throw
 * InvalidOrderTransitionException — no silent failures.
 *
 * Transition map:
 *   pending          → payment_pending, confirmed, cancelled, on_hold
 *   payment_pending  → confirmed, cancelled
 *   confirmed        → processing, cancelled, on_hold
 *   processing       → shipped, cancelled, on_hold
 *   shipped          → delivered
 *   delivered        → refund_requested
 *   refund_requested → refunded, confirmed  (confirmed = refund denied)
 *   on_hold          → confirmed, cancelled
 *   cancelled        → (terminal)
 *   refunded         → (terminal)
 */
class OrderStateMachine
{
    private const ALLOWED_TRANSITIONS = [
        Order::STATUS_PENDING          => [Order::STATUS_PAYMENT_PENDING, Order::STATUS_CONFIRMED, Order::STATUS_CANCELLED, Order::STATUS_ON_HOLD],
        Order::STATUS_PAYMENT_PENDING  => [Order::STATUS_CONFIRMED, Order::STATUS_CANCELLED],
        Order::STATUS_CONFIRMED        => [Order::STATUS_PROCESSING, Order::STATUS_CANCELLED, Order::STATUS_ON_HOLD],
        Order::STATUS_PROCESSING       => [Order::STATUS_SHIPPED, Order::STATUS_CANCELLED, Order::STATUS_ON_HOLD],
        Order::STATUS_SHIPPED          => [Order::STATUS_DELIVERED],
        Order::STATUS_DELIVERED        => [Order::STATUS_REFUND_REQUESTED],
        Order::STATUS_REFUND_REQUESTED => [Order::STATUS_REFUNDED, Order::STATUS_CONFIRMED],
        Order::STATUS_ON_HOLD          => [Order::STATUS_CONFIRMED, Order::STATUS_CANCELLED],
        Order::STATUS_CANCELLED        => [], // terminal
        Order::STATUS_REFUNDED         => [], // terminal
    ];

    /**
     * Transition an order to a new status.
     * Writes to order_status_history audit trail automatically.
     *
     * @throws InvalidOrderTransitionException
     */
    public function transition(Order $order, string $toStatus, ?User $actor = null, ?string $note = null): Order
    {
        $fromStatus = $order->status;

        $this->assertValidTransition($fromStatus, $toStatus);

        return DB::transaction(function () use ($order, $fromStatus, $toStatus, $actor, $note) {
            $order->update(['status' => $toStatus]);

            OrderStatusHistory::create([
                'order_id'   => $order->id,
                'from_status'=> $fromStatus,
                'to_status'  => $toStatus,
                'changed_by' => $actor?->id,
                'note'       => $note,
            ]);

            // Side effects per transition
            $this->handleSideEffects($order, $toStatus, $actor);

            return $order->fresh();
        });
    }

    /**
     * Check if a transition is valid without executing it.
     */
    public function canTransition(string $fromStatus, string $toStatus): bool
    {
        return in_array($toStatus, self::ALLOWED_TRANSITIONS[$fromStatus] ?? [], true);
    }

    /**
     * Return all valid next statuses from a given status.
     */
    public function nextStatuses(string $fromStatus): array
    {
        return self::ALLOWED_TRANSITIONS[$fromStatus] ?? [];
    }

    /**
     * @throws InvalidOrderTransitionException
     */
    private function assertValidTransition(string $from, string $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw new InvalidOrderTransitionException(
                "Invalid order transition: cannot move from [{$from}] to [{$to}]. " .
                "Allowed next statuses: [" . implode(', ', $this->nextStatuses($from)) . "]"
            );
        }
    }

    /**
     * Handle automatic side effects when entering certain states.
     */
    private function handleSideEffects(Order $order, string $toStatus, ?User $actor): void
    {
        match ($toStatus) {
            Order::STATUS_CONFIRMED  => $this->onConfirmed($order),
            Order::STATUS_DELIVERED  => $this->onDelivered($order),
            Order::STATUS_CANCELLED  => $this->onCancelled($order),
            Order::STATUS_REFUNDED   => $this->onRefunded($order),
            default                  => null,
        };
    }

    private function onConfirmed(Order $order): void
    {
        $order->update(['approved_at' => now()]);
    }

    private function onDelivered(Order $order): void
    {
        $order->update(['delivered_at' => now()]);

        // Update sales count for each product
        foreach ($order->items as $item) {
            \App\Modules\Product\Models\Product::where('id', $item->product_id)
                ->increment('sales_count', $item->quantity);
        }
    }

    private function onCancelled(Order $order): void
    {
        // Release inventory reservations
        foreach ($order->items as $item) {
            if ($item->variant_id) {
                \App\Modules\Product\Models\ProductVariant::where('id', $item->variant_id)
                    ->increment('stock_quantity', $item->quantity);
            }

            \App\Modules\Order\Models\InventoryMovement::create([
                'product_id'     => $item->product_id,
                'variant_id'     => $item->variant_id,
                'type'           => 'release',
                'quantity'       => $item->quantity,
                'balance_after'  => 0, // recalculated by inventory service
                'reference_type' => 'order',
                'reference_id'   => $order->id,
                'note'           => "Released: order {$order->order_number} cancelled",
            ]);
        }

        // Release credit if paid via credit account
        if ($order->payment_method === 'credit_account') {
            \App\Modules\User\Models\User::where('id', $order->customer_id)
                ->decrement('credit_used', $order->total_amount);
        }
    }

    private function onRefunded(Order $order): void
    {
        $order->update(['payment_status' => Order::PAYMENT_REFUNDED]);

        // Release credit if applicable
        if ($order->payment_method === 'credit_account') {
            \App\Modules\User\Models\User::where('id', $order->customer_id)
                ->decrement('credit_used', $order->total_amount);
        }
    }
}
