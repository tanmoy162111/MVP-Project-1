<?php
namespace App\Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;

class ProductAttribute extends Model {
    protected $fillable = ['product_id','attribute_name','attribute_value','sort_order'];

    public function product() { return $this->belongsTo(Product::class); }
}
