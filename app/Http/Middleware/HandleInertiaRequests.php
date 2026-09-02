<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $usuario = $request->user();

        $usuario?->loadMissing('rol.permisos');

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $usuario ? [
                    'id' => $usuario->id,
                    'name' => $usuario->name,
                    'email' => $usuario->email,
                    'rol' => $usuario->rol?->nombre,
                    'es_dueno' => $usuario->esDueno(),
                ] : null,
                'permisos' => $usuario?->permisos() ?? [],
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
            ],
        ];
    }
}
