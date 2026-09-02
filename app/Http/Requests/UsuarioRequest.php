<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UsuarioRequest extends FormRequest
{
    /**
     * Reglas para crear o actualizar un usuario.
     *
     * La contraseña es obligatoria al crear; al editar es opcional y solo se
     * actualiza si se informa una nueva (nunca se sobrescribe con vacío).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $usuario = $this->route('usuario');
        $esCreacion = $usuario === null;

        $reglas = [
            'name' => ['required', 'string', 'max:100'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('usuarios', 'email')->ignore($usuario?->id),
            ],
            'rol_id' => ['required', 'integer', 'exists:roles,id'],
            'activo' => ['sometimes', 'boolean'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ];

        if ($esCreacion) {
            $reglas['password'] = ['required', 'string', 'min:8', 'confirmed'];
        }

        return $reglas;
    }
}
