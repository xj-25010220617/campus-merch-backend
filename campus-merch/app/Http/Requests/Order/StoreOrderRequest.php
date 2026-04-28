<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id'       => ['required', Rule::exists('products', 'id')->where('status', 'active')],
            'quantity'         => ['required', 'integer', 'min:1', 'max:999'],
            'size'             => ['nullable', 'string', 'max:50'],
            'color'            => ['nullable', 'string', 'max:50'],
            'shipping_address' => ['nullable', 'string', 'max:500'],
            'remark'           => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.exists' => '商品不存在或已下架',
            'quantity.max'      => '单次购买数量不能超过999',
        ];
    }
}