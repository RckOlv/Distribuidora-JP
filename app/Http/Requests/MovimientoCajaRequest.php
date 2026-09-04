<?php

namespace App\Http\Requests;

use App\Enums\MedioPago;
use App\Enums\TipoMovimientoCaja;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MovimientoCajaRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::in([TipoMovimientoCaja::INGRESO->value, TipoMovimientoCaja::EGRESO->value])],
            'monto' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'medio_pago' => ['nullable', Rule::in(array_column(MedioPago::cases(), 'value'))],
            'concepto' => ['required', 'string', 'max:200'],
        ];
    }
}
