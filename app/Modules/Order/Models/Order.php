<?php
namespace App\Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model {
    use SoftDeletes;

    const STATUS_PENDING='pending',STATUS_PAYMENT_PENDING='payment_pending',STATUS_CONFIRMED='confirmed',STATUS_PROCESSING='processing',STATUS_SHIPPED='shipped',STATUS_DELIVERED='delivered',STATUS_CANCELLED='cancelled',STATUS_REFUND_REQUESTED='refund_requested',STATUS_REFUNDED='refunded',STATUS_ON_HOLD='on_hold';
    const PAYMENT_PENDING='pending',PAYMENT_PAID='paid',PAYMENT_REFUNDED='refunded';

    protected $fillable = ['order_number','customer_id','status','subtotal','freight_cost','tax_amount','discount_amount','total_amount','price_snapshot','payment_method','payment_status','shipping_address','delivery_notes','delivered_at','approved_by','approved_at','cancellation_reason'];

    protected function casts(): array {
        return ['subtotal'=>'decimal:2','freight_cost'=>'decimal:2','tax_amount'=>'decimal:2','discount_amount'=>'decimal:2','total_amount'=>'decimal:2','price_snapshot'=>'array','shipping_address'=>'array','delivered_at'=>'datetime','approved_at'=>'datetime'];
    }

    public function customer() { return $this->belongsTo(\App\Modules\User\Models\User::class,'customer_id'); }
    public function items() { return $this->hasMany(OrderItem::class); }
    public function statusHistory() { return $this->hasMany(OrderStatusHistory::class)->latest(); }
    public function isPending(): bool { return $this->status===self::STATUS_PENDING; }
    public function isConfirmed(): bool { return $this->status===self::STATUS_CONFIRMED; }
    public function isCancelled(): bool { return $this->status===self::STATUS_CANCELLED; }
}
