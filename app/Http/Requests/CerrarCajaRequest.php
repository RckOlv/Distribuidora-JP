<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CerrarCajaRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'efectivo_contado' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'observacion' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
