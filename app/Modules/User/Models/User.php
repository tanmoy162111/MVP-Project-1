<?php
namespace App\Modules\User\Models;

use Database\Factories\UserFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Authenticatable {
    use HasApiTokens, HasRoles, SoftDeletes, HasFactory;

    protected $fillable = ['name','email','password','phone','company_name','type','status','customer_tier','credit_limit','credit_used','last_login_at'];
    protected $hidden = ['password','remember_token'];

    protected function casts(): array {
        return ['email_verified_at'=>'datetime','last_login_at'=>'datetime','credit_limit'=>'decimal:2','credit_used'=>'decimal:2'];
    }

    protected static function newFactory()
    {
        return UserFactory::new();
    }

    public function vendor() { return $this->hasOne(\App\Modules\Vendor\Models\Vendor::class); }
    public function availableCredit(): float { return max(0, (float)$this->credit_limit - (float)$this->credit_used); }
    public function isActive(): bool { return $this->status === 'active'; }
    public function isAdmin(): bool { return $this->type === 'admin'; }
    public function isVendor(): bool { return $this->type === 'vendor'; }
    public function isCustomer(): bool { return $this->type === 'customer'; }
}
