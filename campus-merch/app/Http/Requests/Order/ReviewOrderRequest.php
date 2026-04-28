<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class ReviewOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'in:approve,reject'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'action.required' => '审核动作不能为空',
            'action.in'       => '审核动作只能是 approve 或 reject',
            'reason.max'      => '驳回原因不能超过255个字符',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->action === 'reject' && empty($this->reason)) {
                $validator->errors()->add('reason', '驳回必须填写原因');
            }
        });
    }
}