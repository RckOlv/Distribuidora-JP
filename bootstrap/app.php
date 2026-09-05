<?php

use App\Http\Middleware\AgregarContentLength;
use App\Http\Middleware\AutenticarDispositivoImpresion;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\VerificarPermiso;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Detrás de un túnel/proxy (p. ej. Dev Tunnels) se confían los headers
        // X-Forwarded-*; el middleware decide en runtime según el entorno.
        $middleware->replace(
            \Illuminate\Http\Middleware\TrustProxies::class,
            TrustProxies::class,
        );

        // Workaround Dev Tunnels: Content-Length explícito para el HTML.
        $middleware->prepend(AgregarContentLength::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'permiso' => VerificarPermiso::class,
            'dispositivo.impresion' => AutenticarDispositivoImpresion::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
