<?php

use App\Http\Controllers\BridgeImpresionController;
use App\Http\Controllers\ImpresionController;
use Illuminate\Support\Facades\Route;

// Cola de impresión: consumida por el futuro print bridge (Fase 5B).
// Protegidas por sesión + permiso; la autenticación específica de bridges
// se agregará en 5B sin tocar el dominio de tickets/impresiones.
Route::middleware(['auth', 'permiso:impresiones.gestionar'])
    ->prefix('impresiones')
    ->group(function () {
        Route::get('/pendientes', [ImpresionController::class, 'pendientes'])
            ->name('impresiones.pendientes');

        Route::put('/{trabajo}/procesando', [ImpresionController::class, 'procesando'])
            ->name('impresiones.procesando');

        Route::put('/{trabajo}/impreso', [ImpresionController::class, 'impreso'])
            ->name('impresiones.impreso');

        Route::put('/{trabajo}/error', [ImpresionController::class, 'error'])
            ->name('impresiones.error');
    });

// API exclusiva del bridge. Autenticación propia de dispositivo (token Bearer).
// El bridge solo opera sobre trabajos: leer/reclamar, confirmar impresión o
// informar error. Sin acceso a ventas, productos, precios ni administración.
Route::middleware('dispositivo.impresion')
    ->prefix('bridge')
    ->group(function () {
        Route::get('/impresiones/pendientes', [BridgeImpresionController::class, 'pendientes'])
            ->name('bridge.impresiones.pendientes');

        Route::put('/impresiones/{trabajo}/impreso', [BridgeImpresionController::class, 'impreso'])
            ->name('bridge.impresiones.impreso');

        Route::put('/impresiones/{trabajo}/error', [BridgeImpresionController::class, 'error'])
            ->name('bridge.impresiones.error');
    });
