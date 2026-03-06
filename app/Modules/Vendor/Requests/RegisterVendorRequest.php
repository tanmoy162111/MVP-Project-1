<?php

namespace App\Modules\Vendor\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterVendorRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'store_name'           => ['required', 'string', 'max:150'],
            'description'          => ['nullable', 'string', 'max:2000'],
            'phone'                => ['nullable', 'string', 'max:20'],
            'email'                => ['nullable', 'email', 'max:150'],
            'address'              => ['nullable', 'string', 'max:500'],
            'trade_license'        => ['nullable', 'string', 'max:100'],
            'tin_number'           => ['nullable', 'string', 'max:50'],
            'bin_number'           => ['nullable', 'string', 'max:50'],
            'bank_name'            => ['nullable', 'string', 'max:100'],
            'bank_account_number'  => ['nullable', 'string', 'max:50'],
            'bank_routing_number'  => ['nullable', 'string', 'max:50'],
        ];
    }
}
