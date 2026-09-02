<?php

namespace App\Http\Requests;

use App\Enums\MedioPago;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\In;

class VentasHistorialRequest extends FormRequest
{
    /**
     * @return array<string, array<int, In|Exists|string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date', 'after_or_equal:fecha_desde'],
            'numero' => ['nullable', 'string', 'max:20'],
            'usuario_id' => ['nullable', 'integer', 'exists:usuarios,id'],
            'medio_pago' => ['nullable', Rule::in(array_column(MedioPago::cases(), 'value'))],
        ];
    }
}
