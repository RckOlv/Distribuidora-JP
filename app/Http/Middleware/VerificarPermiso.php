<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerificarPermiso
{
    /**
     * Verifica que el usuario autenticado posea al menos uno de los permisos indicados.
     *
     * Uso: ->middleware('permiso:productos.crear,productos.editar')
     */
    public function handle(Request $request, Closure $next, string ...$permisos): Response
    {
        $usuario = $request->user();

        abort_unless(
            $usuario !== null && collect($permisos)->some(
                fn (string $permiso) => $usuario->can($permiso)
            ),
            403,
        );

        return $next($request);
    }
}
