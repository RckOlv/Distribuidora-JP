<?php

namespace App\Http\Requests;

use App\Models\Categoria;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CategoriaRequest extends FormRequest
{
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('nombre')) {
            $this->merge(['nombre' => trim((string) $this->input('nombre'))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $categoria = $this->route('categoria');

        return [
            'nombre' => [
                'required',
                'string',
                'min:2',
                'max:100',
                'regex:/[\p{L}]/u',
                Rule::unique('categorias', 'nombre')->ignore($categoria?->id),
            ],
            'descripcion' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('nombre')) {
                return;
            }

            $existe = Categoria::query()
                ->whereRaw('LOWER(BTRIM(nombre)) = ?', [mb_strtolower((string) $this->input('nombre'))])
                ->when($this->route('categoria'), fn ($q, $categoria) => $q->where('id', '!=', $categoria->id))
                ->exists();

            if ($existe) {
                $validator->errors()->add('nombre', 'El nombre de la categoría ya existe.');
            }
        });
    }

    /**
     * Mensajes de validación en español.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre de la categoría es obligatorio.',
            'nombre.min' => 'El nombre de la categoría debe tener al menos :min caracteres.',
            'nombre.max' => 'El nombre de la categoría no debe superar los :max caracteres.',
            'nombre.regex' => 'El nombre de la categoría no puede ser solo números.',
            'nombre.unique' => 'El nombre de la categoría ya existe.',
        ];
    }
}
