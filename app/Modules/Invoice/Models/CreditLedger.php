<?php
namespace App\Modules\Invoice\Models;
use Illuminate\Database\Eloquent\Model;
class CreditLedger extends Model {
    protected $table = 'credit_ledger';
    protected $fillable = ['customer_id','type','amount','balance_after','reference_type','reference_id','created_by','note'];
    protected function casts(): array { return ['amount'=>'decimal:2','balance_after'=>'decimal:2']; }
    public function customer() { return $this->belongsTo(\App\Modules\User\Models\User::class,'customer_id'); }
}
