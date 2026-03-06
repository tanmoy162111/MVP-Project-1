<?php

namespace App\Modules\Order\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransitionOrderRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:payment_pending,confirmed,processing,shipped,delivered,cancelled,refund_requested,refunded,on_hold'],
            'note'   => ['nullable', 'string', 'max:500'],
        ];
    }
}
