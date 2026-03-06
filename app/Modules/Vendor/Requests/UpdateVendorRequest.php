<?php

namespace App\Modules\Vendor\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVendorRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'store_name'           => ['sometimes', 'string', 'max:150'],
            'description'          => ['sometimes', 'nullable', 'string', 'max:2000'],
            'phone'                => ['sometimes', 'nullable', 'string', 'max:20'],
            'email'                => ['sometimes', 'nullable', 'email', 'max:150'],
            'address'              => ['sometimes', 'nullable', 'string', 'max:500'],
            'trade_license'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'tin_number'           => ['sometimes', 'nullable', 'string', 'max:50'],
            'bin_number'           => ['sometimes', 'nullable', 'string', 'max:50'],
            'bank_name'            => ['sometimes', 'nullable', 'string', 'max:100'],
            'bank_account_number'  => ['sometimes', 'nullable', 'string', 'max:50'],
            'bank_routing_number'  => ['sometimes', 'nullable', 'string', 'max:50'],
            'commission_rate'      => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
