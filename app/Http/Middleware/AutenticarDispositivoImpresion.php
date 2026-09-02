<?php

namespace App\Http\Middleware;

use App\Models\DispositivoImpresion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica un print bridge mediante token Bearer de dispositivo.
 *
 * Guarda el dispositivo autenticado en el request bajo la clave 'dispositivo'.
 */
class AutenticarDispositivoImpresion
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        $dispositivo = $token === null
            ? null
            : DispositivoImpresion::query()
                ->where('activo', true)
                ->get()
                ->first(fn (DispositivoImpresion $item) => $item->verificarToken($token));

        if ($dispositivo === null) {
            return response()->json(['message' => 'No autorizado.'], 401);
        }

        $dispositivo->marcarConexion();

        $request->attributes->set('dispositivo', $dispositivo);

        return $next($request);
    }
}
