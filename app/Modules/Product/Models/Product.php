<?php
namespace App\Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model {
    use SoftDeletes, HasFactory;

    protected $fillable = ['vendor_id','category_id','brand_id','name','slug','short_description','description','sku','base_price','cost_price','status','condition','is_featured','weight','dimensions','warranty_period','warranty_terms','meta_title','meta_description','view_count','sales_count'];

    protected function casts(): array {
        return ['base_price'=>'decimal:2','cost_price'=>'decimal:2','dimensions'=>'array','is_featured'=>'boolean'];
    }

    public function vendor() { return $this->belongsTo(\App\Modules\Vendor\Models\Vendor::class); }
    public function category() { return $this->belongsTo(Category::class); }
    public function brand() { return $this->belongsTo(Brand::class); }
    public function variants() { return $this->hasMany(ProductVariant::class); }
    public function attributes() { return $this->hasMany(ProductAttribute::class)->orderBy('sort_order'); }
    public function images() { return $this->hasMany(ProductImage::class)->orderBy('sort_order'); }
    public function primaryImage() { return $this->hasOne(ProductImage::class)->where('is_primary',true); }
    public function scopeActive($q) { return $q->where('status','active'); }
    public function scopeFeatured($q) { return $q->where('is_featured',true); }
}
