<?php

namespace App\Modules\Product\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $productId = $this->route('id');

        return [
            'category_id'       => ['sometimes', 'exists:categories,id'],
            'brand_id'          => ['nullable', 'exists:brands,id'],
            'name'              => ['sometimes', 'string', 'max:200'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description'       => ['nullable', 'string'],
            'sku'               => ["nullable", "string", "max:100", "unique:products,sku,{$productId}"],
            'base_price'        => ['sometimes', 'numeric', 'min:0'],
            'cost_price'        => ['nullable', 'numeric', 'min:0'],
            'condition'         => ['nullable', 'in:new,refurbished,used'],
            'weight'            => ['nullable', 'numeric'],
            'dimensions'        => ['nullable', 'array'],
            'warranty_period'   => ['nullable', 'string', 'max:50'],
            'warranty_terms'    => ['nullable', 'string'],
            'meta_title'        => ['nullable', 'string', 'max:200'],
            'meta_description'  => ['nullable', 'string', 'max:500'],
            'attributes'        => ['nullable', 'array'],
            'attributes.*.name' => ['required_with:attributes', 'string', 'max:100'],
            'attributes.*.value'=> ['required_with:attributes', 'string', 'max:255'],
        ];
    }
}
