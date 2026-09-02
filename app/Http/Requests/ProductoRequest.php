<?php

namespace App\Http\Requests;

use App\Enums\UnidadVenta;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductoRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $producto = $this->route('producto');

        return [
            'nombre' => ['required', 'string', 'max:150'],
            'categoria_id' => ['required', 'integer', Rule::exists('categorias', 'id')],
            'unidad_medida' => ['required', Rule::enum(UnidadVenta::class)],
            'codigo' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('productos', 'codigo')->ignore($producto?->id),
            ],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'monto' => ['nullable', 'numeric', 'min:0.01', 'max:9999999999'],
            'imagen' => ['nullable', 'image', 'max:5120'],
            'quitar_imagen' => ['nullable', 'boolean'],
        ];
    }
}
