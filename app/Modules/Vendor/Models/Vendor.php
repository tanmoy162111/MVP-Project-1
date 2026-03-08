<?php
namespace App\Modules\Vendor\Models;

use Database\Factories\VendorFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Vendor extends Model {
    use SoftDeletes, HasFactory;

    protected $fillable = ['user_id','store_name','slug','description','logo','banner','phone','email','address','trade_license','tin_number','bin_number','documents','bank_name','bank_account_number','bank_routing_number','commission_rate','status','rejection_reason','approved_at','approved_by','total_sales','total_revenue','average_rating','rating_count'];

    protected function casts(): array {
        return ['documents'=>'array','approved_at'=>'datetime','commission_rate'=>'decimal:2','total_revenue'=>'decimal:2'];
    }

    protected static function newFactory()
    {
        return VendorFactory::new();
    }

    public function user() { return $this->belongsTo(\App\Modules\User\Models\User::class); }
    public function products() { return $this->hasMany(\App\Modules\Product\Models\Product::class); }
    public function isActive(): bool { return $this->status === 'active'; }
}
