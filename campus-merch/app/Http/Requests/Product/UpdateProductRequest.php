<?php

namespace App\Http\Requests\Product;

use App\Enums\ProductStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'string', 'max:100'],
            'type' => ['sometimes', 'string', 'max:100'],
            'spec' => ['nullable', 'string', 'max:255'],
            'price' => ['sometimes', 'numeric', 'min:0.01'],
            'stock' => ['sometimes', 'integer', 'min:0'],
            'cover_url' => ['nullable', 'string', 'max:2048'],
            'custom_rule' => ['nullable', 'string'],
            'status' => ['sometimes', new Enum(ProductStatus::class)],
            'version' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
