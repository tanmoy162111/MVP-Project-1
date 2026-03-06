<?php

namespace App\Modules\User\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'type',           // customer | vendor | admin
        'status',         // active | suspended | pending
        'customer_tier',  // standard | silver | gold | corporate
        'credit_limit',
        'credit_used',
        'avatar',
        'email_verified_at',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'password'          => 'hashed',
            'credit_limit'      => 'decimal:2',
            'credit_used'       => 'decimal:2',
        ];
    }

    // ── RELATIONSHIPS ─────────────────────────────────────────────────────────

    public function vendor(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Modules\Vendor\Models\Vendor::class);
    }

    public function orders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Modules\Order\Models\Order::class, 'customer_id');
    }

    public function invoices(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Modules\Invoice\Models\Invoice::class, 'customer_id');
    }

    // ── HELPERS ───────────────────────────────────────────────────────────────

    public function isAdmin(): bool
    {
        return $this->type === 'admin';
    }

    public function isVendor(): bool
    {
        return $this->type === 'vendor';
    }

    public function isCustomer(): bool
    {
        return $this->type === 'customer';
    }

    public function availableCredit(): float
    {
        return max(0, (float) $this->credit_limit - (float) $this->credit_used);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
