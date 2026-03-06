<?php

namespace App\Modules\Product\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateProductRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'category_id'       => ['required', 'exists:categories,id'],
            'brand_id'          => ['nullable', 'exists:brands,id'],
            'name'              => ['required', 'string', 'max:200'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description'       => ['nullable', 'string'],
            'sku'               => ['nullable', 'string', 'max:100', 'unique:products,sku'],
            'base_price'        => ['required', 'numeric', 'min:0'],
            'cost_price'        => ['nullable', 'numeric', 'min:0'],
            'condition'         => ['nullable', 'in:new,refurbished,used'],
            'weight'            => ['nullable', 'numeric', 'min:0'],
            'dimensions'        => ['nullable', 'array'],
            'dimensions.length' => ['nullable', 'numeric'],
            'dimensions.width'  => ['nullable', 'numeric'],
            'dimensions.height' => ['nullable', 'numeric'],
            'warranty_period'   => ['nullable', 'string', 'max:50'],
            'warranty_terms'    => ['nullable', 'string'],
            'meta_title'        => ['nullable', 'string', 'max:200'],
            'meta_description'  => ['nullable', 'string', 'max:500'],

            // Variants
            'variants'                       => ['nullable', 'array'],
            'variants.*.sku'                 => ['nullable', 'string', 'max:100'],
            'variants.*.name'                => ['nullable', 'string', 'max:200'],
            'variants.*.attributes'          => ['required_with:variants', 'array'],
            'variants.*.price_adjustment'    => ['nullable', 'numeric'],
            'variants.*.stock_quantity'      => ['nullable', 'integer', 'min:0'],
            'variants.*.low_stock_threshold' => ['nullable', 'integer', 'min:0'],

            // Attributes / specs
            'attributes'        => ['nullable', 'array'],
            'attributes.*.name' => ['required_with:attributes', 'string', 'max:100'],
            'attributes.*.value'=> ['required_with:attributes', 'string', 'max:255'],
        ];
    }
}
