<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'product_id'       => ['required', 'exists:products,id'],
            'quantity'         => ['required', 'integer', 'min:1'],
            'size'             => ['nullable', 'string', 'max:50'],
            'color'            => ['nullable', 'string', 'max:50'],
            'shipping_address' => ['nullable', 'string', 'max:500'],
            'remark'           => ['nullable', 'string', 'max:500'],
        ];
    }
}