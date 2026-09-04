<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReporteRequest extends FormRequest
{
    /**
     * Acepta un rango de fechas (desde/hasta) y mantiene la compatibilidad
     * con la fecha puntual heredada ('fecha').
     *
     * @return array<string, array<int, string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * El rango no puede estar invertido: desde no puede ser posterior a hasta.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $desde = $validator->getData()['desde'] ?? null;
            $hasta = $validator->getData()['hasta'] ?? null;

            if ($desde !== null && $hasta !== null && $desde > $hasta) {
                $validator->errors()->add(
                    'desde',
                    'La fecha desde no puede ser posterior a la fecha hasta.',
                );
            }
        });
    }
}
