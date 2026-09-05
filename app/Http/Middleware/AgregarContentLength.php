<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Workaround para Dev Tunnels: la relay no delimita el body del HTML dinámico
 * (HTTP/2 sin Content-Length), dejando al navegador esperando el fin del
 * documento para siempre. Se agrega Content-Length explícito.
 *
 * Solo aplica en entorno local/desarrollo.
 */
class AgregarContentLength
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! app()->isLocal()) {
            return $response;
        }

        return $this->agregarLongitud($response);
    }

    private function agregarLongitud(mixed $response): mixed
    {
        if (! $response instanceof Response) {
            return $response;
        }

        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return $response;
        }

        if ($response->headers->has('Content-Length')) {
            return $response;
        }

        if (in_array($response->getStatusCode(), [204, 304], true)) {
            return $response;
        }

        $contentType = strtolower($response->headers->get('Content-Type') ?? '');
        if (! str_contains($contentType, 'text/html')) {
            return $response;
        }

        $response->headers->set('Content-Length', (string) strlen((string) $response->getContent()));

        return $response;
    }
}