<?php
namespace App\Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImage extends Model {
    protected $fillable = ['product_id','path','alt_text','sort_order','is_primary'];

    protected function casts(): array {
        return ['is_primary'=>'boolean'];
    }
}
