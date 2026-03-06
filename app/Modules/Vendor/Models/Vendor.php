<?php

namespace App\Modules\Vendor\Models;

use Database\Factories\VendorFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Vendor extends Model
{
    use SoftDeletes, HasFactory;

    protected $fillable = [
        'user_id', 'store_name', 'slug', 'description', 'logo', 'banner',
        'phone', 'email', 'address',
        'trade_license', 'tin_number', 'bin_number', 'documents',
        'bank_name', 'bank_account_number', 'bank_routing_number',
        'commission_rate', 'status', 'rejection_reason',
        'approved_at', 'approved_by',
        'total_sales', 'total_revenue', 'average_rating', 'rating_count',
    ];

    protected function casts(): array
    {
        return [
            'documents'    => 'array',
            'approved_at'  => 'datetime',
            'commission_rate' => 'decimal:2',
            'total_revenue'   => 'decimal:2',
            'average_rating'  => 'decimal:2',
        ];
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return VendorFactory::new();
    }

    // ── RELATIONSHIPS ─────────────────────────────────────────────────────────

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\User\Models\User::class);
    }

    public function products(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Modules\Product\Models\Product::class);
    }

    public function approvedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\User\Models\User::class, 'approved_by');
    }

    // ── SCOPES ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    // ── HELPERS ───────────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
