<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class ReviewOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'action' => ['required', 'in:approve,reject'],
            'reason' => ['nullable', 'string', 'max:255']
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($this->action === 'reject' && empty($this->reason)) {
                $validator->errors()->add('reason', '驳回必须填写原因');
            }
        });
    }
}