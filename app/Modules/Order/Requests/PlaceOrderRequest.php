<?php

namespace App\Modules\Order\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'items'                    => ['required', 'array', 'min:1'],
            'items.*.product_id'       => ['required', 'integer', 'exists:products,id'],
            'items.*.variant_id'       => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.quantity'         => ['required', 'integer', 'min:1', 'max:999'],

            'payment_method'           => ['required', 'in:bkash,nagad,sslcommerz,bank_transfer,cod,credit_account'],

            'shipping_address'         => ['required', 'array'],
            'shipping_address.name'    => ['required', 'string', 'max:100'],
            'shipping_address.phone'   => ['required', 'string', 'max:20'],
            'shipping_address.line1'   => ['required', 'string', 'max:200'],
            'shipping_address.line2'   => ['nullable', 'string', 'max:200'],
            'shipping_address.city'    => ['required', 'string', 'max:100'],
            'shipping_address.district'=> ['nullable', 'string', 'max:100'],
            'shipping_address.postcode'=> ['nullable', 'string', 'max:20'],

            'delivery_notes'           => ['nullable', 'string', 'max:500'],
            'freight_cost'             => ['nullable', 'numeric', 'min:0'],
            'discount_amount'          => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required'              => 'Your cart is empty.',
            'items.*.product_id.exists'   => 'One or more products in your cart no longer exist.',
            'items.*.variant_id.exists'   => 'One or more product variants in your cart are unavailable.',
            'payment_method.required'     => 'Please select a payment method.',
            'shipping_address.required'   => 'A delivery address is required.',
        ];
    }
}
