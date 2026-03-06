<?php

namespace App\Modules\Order\Services;

use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderItem;
use App\Modules\Order\Models\InventoryMovement;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductVariant;
use App\Modules\User\Models\User;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\CreditLimitExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderService
{
    public function __construct(private OrderStateMachine $stateMachine) {}

    /**
     * Place a new order.
     *
     * Flow:
     * 1. Validate stock for each line item
     * 2. If payment_method = credit_account → enforce credit limit
     * 3. Create order + items in single transaction
     * 4. Reserve inventory (decrement stock)
     * 5. Log inventory movements
     * 6. Charge credit if applicable
     *
     * @throws InsufficientStockException
     * @throws CreditLimitExceededException
     */
    public function placeOrder(User $customer, array $data): Order
    {
        // ── STEP 1: Validate stock ────────────────────────────────────────────
        $lineItems = $this->resolveLineItems($data['items']);

        // ── STEP 2: Calculate totals ──────────────────────────────────────────
        $subtotal = collect($lineItems)->sum('total_price');
        $freight  = (float) ($data['freight_cost'] ?? 0);
        $tax      = round($subtotal * 0.05, 2); // 5% VAT — will be replaced by PricingEngine in Phase 4
        $discount = (float) ($data['discount_amount'] ?? 0);
        $total    = $subtotal + $freight + $tax - $discount;

        // ── STEP 3: Credit limit check ────────────────────────────────────────
        if (($data['payment_method'] ?? '') === 'credit_account') {
            $this->enforceCreditLimit($customer, $total);
        }

        return DB::transaction(function () use ($customer, $data, $lineItems, $subtotal, $freight, $tax, $discount, $total) {

            // ── STEP 4: Create order ──────────────────────────────────────────
            $order = Order::create([
                'order_number'    => $this->generateOrderNumber(),
                'customer_id'     => $customer->id,
                'status'          => Order::STATUS_PENDING,
                'subtotal'        => $subtotal,
                'freight_cost'    => $freight,
                'tax_amount'      => $tax,
                'discount_amount' => $discount,
                'total_amount'    => $total,
                'payment_method'  => $data['payment_method'] ?? null,
                'payment_status'  => Order::PAYMENT_PENDING,
                'shipping_address'=> $data['shipping_address'],
                'delivery_notes'  => $data['delivery_notes'] ?? null,
            ]);

            // ── STEP 5: Create order items + reserve inventory ────────────────
            foreach ($lineItems as $item) {
                OrderItem::create([
                    'order_id'      => $order->id,
                    'vendor_id'     => $item['vendor_id'],
                    'product_id'    => $item['product_id'],
                    'variant_id'    => $item['variant_id'],
                    'product_name'  => $item['product_name'],
                    'variant_name'  => $item['variant_name'],
                    'sku'           => $item['sku'],
                    'quantity'      => $item['quantity'],
                    'unit_price'    => $item['unit_price'],
                    'total_price'   => $item['total_price'],
                    'vendor_payout' => $this->calculateVendorPayout($item),
                    'status'        => 'pending',
                ]);

                // Decrement stock — wrapped in this transaction
                if ($item['variant_id']) {
                    ProductVariant::where('id', $item['variant_id'])
                        ->decrement('stock_quantity', $item['quantity']);

                    $newStock = ProductVariant::find($item['variant_id'])->stock_quantity;
                } else {
                    $newStock = 0;
                }

                // Ledger entry
                InventoryMovement::create([
                    'product_id'     => $item['product_id'],
                    'variant_id'     => $item['variant_id'],
                    'type'           => 'reservation',
                    'quantity'       => -$item['quantity'],
                    'balance_after'  => $newStock,
                    'reference_type' => 'order',
                    'reference_id'   => $order->id,
                    'note'           => "Reserved for order {$order->order_number}",
                ]);
            }

            // ── STEP 6: Charge credit if applicable ───────────────────────────
            if ($data['payment_method'] === 'credit_account') {
                User::where('id', $customer->id)->increment('credit_used', $total);

                \App\Modules\Invoice\Models\CreditLedger::create([
                    'customer_id'    => $customer->id,
                    'type'           => 'charge',
                    'amount'         => $total,
                    'balance_after'  => $customer->credit_used + $total,
                    'reference_type' => 'order',
                    'reference_id'   => $order->id,
                    'note'           => "Charged for order {$order->order_number}",
                ]);
            }

            return $order->load('items');
        });
    }

    /**
     * Admin confirms an order (moves pending → confirmed).
     */
    public function confirm(Order $order, User $admin, ?string $note = null): Order
    {
        $order = $this->stateMachine->transition($order, Order::STATUS_CONFIRMED, $admin, $note);
        $order->update(['approved_by' => $admin->id]);
        return $order;
    }

    /**
     * Cancel an order — admin or customer (with restrictions).
     *
     * @throws InvalidOrderTransitionException
     */
    public function cancel(Order $order, User $actor, string $reason): Order
    {
        $order = $this->stateMachine->transition($order, Order::STATUS_CANCELLED, $actor, $reason);
        $order->update(['cancellation_reason' => $reason]);
        return $order;
    }

    /**
     * Move order through any valid transition — used by admin panel.
     *
     * @throws InvalidOrderTransitionException
     */
    public function transition(Order $order, string $toStatus, User $actor, ?string $note = null): Order
    {
        return $this->stateMachine->transition($order, $toStatus, $actor, $note);
    }

    // ── PRIVATE HELPERS ───────────────────────────────────────────────────────

    /**
     * Resolve submitted cart items to full line items with stock checks.
     *
     * @throws InsufficientStockException
     */
    private function resolveLineItems(array $items): array
    {
        $resolved = [];

        foreach ($items as $item) {
            $product = Product::active()->findOrFail($item['product_id']);
            $variant = isset($item['variant_id'])
                ? ProductVariant::where('product_id', $product->id)->findOrFail($item['variant_id'])
                : null;

            $quantity = (int) $item['quantity'];

            // Stock check
            if ($variant) {
                if ($variant->stock_quantity < $quantity) {
                    throw new InsufficientStockException(
                        "Insufficient stock for \"{$product->name}\" ({$variant->name}). " .
                        "Available: {$variant->stock_quantity}, Requested: {$quantity}."
                    );
                }
                $unitPrice = (float) $product->base_price + (float) $variant->price_adjustment;
            } else {
                $unitPrice = (float) $product->base_price;
            }

            $resolved[] = [
                'vendor_id'    => $product->vendor_id,
                'product_id'   => $product->id,
                'variant_id'   => $variant?->id,
                'product_name' => $product->name,
                'variant_name' => $variant?->name,
                'sku'          => $variant?->sku ?? $product->sku,
                'quantity'     => $quantity,
                'unit_price'   => $unitPrice,
                'total_price'  => round($unitPrice * $quantity, 2),
                'commission_rate' => $product->vendor->commission_rate,
            ];
        }

        return $resolved;
    }

    /**
     * Calculate how much the vendor receives after platform commission.
     */
    private function calculateVendorPayout(array $item): float
    {
        $commission = $item['commission_rate'] ?? 10;
        return round($item['total_price'] * (1 - $commission / 100), 2);
    }

    /**
     * @throws CreditLimitExceededException
     */
    private function enforceCreditLimit(User $customer, float $orderTotal): void
    {
        $available = $customer->availableCredit();

        if ($orderTotal > $available) {
            throw new CreditLimitExceededException(
                "Order total (BDT " . number_format($orderTotal, 2) . ") " .
                "exceeds your available credit (BDT " . number_format($available, 2) . "). " .
                "Please use a different payment method or contact your account manager."
            );
        }
    }

    private function generateOrderNumber(): string
    {
        $year     = now()->format('Y');
        $sequence = str_pad(Order::whereYear('created_at', $year)->count() + 1, 6, '0', STR_PAD_LEFT);
        return "ORD-{$year}-{$sequence}";
    }
}
