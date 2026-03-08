<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Notification
 * In-app notifications for customers and staff.
 */
class Notification extends Model
{
    const TYPE_ORDER_STATUS   = 'order_status';
    const TYPE_INVOICE_ISSUED = 'invoice_issued';
    const TYPE_PAYMENT_RECEIVED = 'payment_received';
    const TYPE_PRICE_ALERT    = 'price_alert';
    const TYPE_STOCK_ALERT    = 'stock_alert';
    const TYPE_SYSTEM         = 'system';

    protected $fillable = [
        'user_id', 'type', 'title', 'body',
        'action_url',
        'related_type', 'related_id',
        'is_read', 'read_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_read'  => 'boolean',
            'read_at'  => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\User\Models\User::class);
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }
}
