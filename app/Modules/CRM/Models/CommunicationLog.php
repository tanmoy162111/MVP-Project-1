<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CommunicationLog
 *
 * Append-only record of every customer interaction — emails, calls,
 * notes, support tickets, and system events. Never updated after creation.
 */
class CommunicationLog extends Model
{
    const TYPE_EMAIL   = 'email';
    const TYPE_CALL    = 'call';
    const TYPE_NOTE    = 'note';
    const TYPE_MEETING = 'meeting';
    const TYPE_SYSTEM  = 'system';   // auto-generated events (order placed, invoice issued, etc.)
    const TYPE_SUPPORT = 'support';

    const DIRECTION_INBOUND  = 'inbound';
    const DIRECTION_OUTBOUND = 'outbound';

    protected $fillable = [
        'customer_id', 'created_by',
        'type', 'direction',
        'subject', 'body',
        'related_type',   // polymorphic: 'order' | 'invoice' | 'contract'
        'related_id',
        'metadata',       // JSON — e.g. email_id, call_duration, ticket_ref
        'is_pinned',
    ];

    protected function casts(): array
    {
        return [
            'metadata'  => 'array',
            'is_pinned' => 'boolean',
        ];
    }

    public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\User\Models\User::class, 'customer_id');
    }

    public function createdBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\User\Models\User::class, 'created_by');
    }

    public function scopePinned($query)
    {
        return $query->where('is_pinned', true);
    }

    public function scopeForCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
    }
}
