<?php

namespace App\Http\Requests\File;

use Illuminate\Foundation\Http\FormRequest;

class UploadOrderDesignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'design_file' => [
                'required',
                'file',
                'max:15360',
                'mimes:jpg,jpeg,png,pdf,ai,psd',
            ],
        ];
    }
}
