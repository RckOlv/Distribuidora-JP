<?php

namespace App\Http\Requests;

use App\Enums\AccionAuditoria;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\In;

class AuditoriaHistorialRequest extends FormRequest
{
    /**
     * @return array<string, array<int, In|Exists|string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date', 'after_or_equal:fecha_desde'],
            'usuario_id' => ['nullable', 'integer', 'exists:usuarios,id'],
            'accion' => ['nullable', Rule::in(array_column(AccionAuditoria::cases(), 'value'))],
            'entidad_tipo' => ['nullable', 'string', 'max:60'],
        ];
    }
}
