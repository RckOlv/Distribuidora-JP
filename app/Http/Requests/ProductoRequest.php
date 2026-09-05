<?php

namespace App\Http\Requests;

use App\Enums\UnidadVenta;
use App\Models\Producto;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductoRequest extends FormRequest
{
    /**
     * Normaliza nombre y código antes de validar.
     *
     * - Recorta espacios al inicio/fin de nombre y código.
     * - Convierte el código vacío (string '') en null para que el índice único
     *   (que trata los null como distintos) no colisione entre productos sin
     *   código.
     */
    protected function prepareForValidation(): void
    {
        $data = $this->all();

        if (array_key_exists('nombre', $data)) {
            $data['nombre'] = trim((string) $data['nombre']);
        }

        if (array_key_exists('codigo', $data)) {
            $codigo = trim((string) $data['codigo']);
            $data['codigo'] = $codigo === '' ? null : $codigo;
        }

        $this->replace($data);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $producto = $this->route('producto');
        $esCreacion = $producto === null;

        // En la creación, el código de barras, el precio de venta y el costo
        // son obligatorios. Al editar, el costo también es obligatorio; solo
        // código y monto quedan opcionales para permitir ediciones parciales.
        $requeridoSiEsCreacion = $esCreacion ? 'required' : 'nullable';

        return [
            'nombre' => ['required', 'string', 'max:150'],
            'categoria_id' => ['required', 'integer', Rule::exists('categorias', 'id')],
            'unidad_medida' => ['required', Rule::enum(UnidadVenta::class)],
            'codigo' => [
                $requeridoSiEsCreacion,
                'string',
                'max:64',
                Rule::unique('productos', 'codigo')->ignore($producto?->id),
            ],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'monto' => [$requeridoSiEsCreacion, 'numeric', 'min:0.01', 'max:9999999999'],
            'costo' => ['required', 'numeric', 'min:0.01', 'max:9999999999'],
            'imagen' => ['nullable', 'image', 'max:5120'],
            'quitar_imagen' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->detectarDuplicado($validator, 'nombre', 'El nombre del producto ya existe.');
            $this->detectarDuplicado($validator, 'codigo', 'El código del producto ya existe.');
        });
    }

    private function detectarDuplicado(
        Validator $validator,
        string $campo,
        string $mensaje,
    ): void {
        if ($validator->errors()->has($campo)) {
            return;
        }

        $valor = $this->input($campo);

        if ($valor === null || trim((string) $valor) === '') {
            return;
        }

        $existe = Producto::query()
            ->whereRaw('LOWER(BTRIM('.$campo.')) = ?', [mb_strtolower(trim((string) $valor))])
            ->when($this->route('producto'), fn ($q, $producto) => $q->where('id', '!=', $producto->id))
            ->exists();

        if ($existe) {
            $validator->errors()->add($campo, $mensaje);
        }
    }

    /**
     * Mensajes de validación en español.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre del producto es obligatorio.',
            'nombre.max' => 'El nombre del producto no debe superar los :max caracteres.',
            'categoria_id.required' => 'Debés seleccionar una categoría.',
            'categoria_id.exists' => 'La categoría seleccionada no es válida.',
            'unidad_medida.required' => 'Debés indicar el tipo de venta.',
            'codigo.required' => 'El código del producto es obligatorio.',
            'codigo.max' => 'El código no debe superar los :max caracteres.',
            'descripcion.max' => 'La descripción no debe superar los :max caracteres.',
            'monto.required' => 'El precio de venta es obligatorio.',
            'monto.min' => 'El precio de venta debe ser al menos :min.',
            'monto.numeric' => 'El precio de venta debe ser un número.',
            'costo.required' => 'El precio de costo es obligatorio.',
            'costo.min' => 'El precio de costo debe ser al menos :min.',
            'costo.numeric' => 'El precio de costo debe ser un número.',
            'imagen.image' => 'El archivo debe ser una imagen.',
            'imagen.max' => 'La imagen no debe superar los :max kilobytes.',
        ];
    }
}
