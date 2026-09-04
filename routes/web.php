<?php

use App\Http\Controllers\AuditoriaController;
use App\Http\Controllers\CajaController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\VentaController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Categorías
    Route::get('/categorias', [CategoriaController::class, 'index'])
        ->middleware('permiso:categorias.ver')
        ->name('categorias.index');
    Route::get('/categorias/crear', [CategoriaController::class, 'create'])
        ->middleware('permiso:categorias.gestionar')
        ->name('categorias.create');
    Route::post('/categorias', [CategoriaController::class, 'store'])
        ->middleware('permiso:categorias.gestionar')
        ->name('categorias.store');
    Route::get('/categorias/{categoria}/editar', [CategoriaController::class, 'edit'])
        ->middleware('permiso:categorias.gestionar')
        ->name('categorias.edit');
    Route::put('/categorias/{categoria}', [CategoriaController::class, 'update'])
        ->middleware('permiso:categorias.gestionar')
        ->name('categorias.update');
    Route::post('/categorias/{categoria}/estado', [CategoriaController::class, 'toggleEstado'])
        ->middleware('permiso:categorias.gestionar')
        ->name('categorias.estado');

    // Productos
    Route::get('/productos', [ProductoController::class, 'index'])
        ->middleware('permiso:productos.ver')
        ->name('productos.index');
    Route::get('/productos/exportar-pdf', [ProductoController::class, 'exportarPdf'])
        ->middleware('permiso:productos.ver')
        ->name('productos.exportar-pdf');
    Route::get('/productos/verificar-nombre', [ProductoController::class, 'verificarNombre'])
        ->middleware('permiso:productos.ver')
        ->name('productos.verificar-nombre');
    Route::get('/productos/crear', [ProductoController::class, 'create'])
        ->middleware('permiso:productos.crear')
        ->name('productos.create');
    Route::post('/productos', [ProductoController::class, 'store'])
        ->middleware(['permiso:productos.crear', 'permiso:precios.gestionar'])
        ->name('productos.store');
    Route::get('/productos/{producto}/editar', [ProductoController::class, 'edit'])
        ->middleware('permiso:productos.editar')
        ->name('productos.edit');
    Route::put('/productos/{producto}', [ProductoController::class, 'update'])
        ->middleware(['permiso:productos.editar', 'permiso:precios.gestionar'])
        ->name('productos.update');
    Route::post('/productos/{producto}/estado', [ProductoController::class, 'toggleEstado'])
        ->middleware('permiso:productos.editar')
        ->name('productos.estado');

    // Punto de venta
    Route::get('/pos', [PosController::class, 'index'])
        ->middleware('permiso:pos.usar')
        ->name('pos.index');

    // Historial de ventas
    Route::get('/ventas', [VentaController::class, 'index'])
        ->middleware('permiso:ventas.ver')
        ->name('ventas.index');
    Route::get('/ventas/{venta}', [VentaController::class, 'show'])
        ->middleware('permiso:ventas.ver')
        ->name('ventas.show');
    Route::get('/ventas/{venta}/ticket-pdf', [VentaController::class, 'ticketPdf'])
        ->middleware('permiso:ventas.ver')
        ->name('ventas.ticket-pdf');

    // Reportes (resumen diario de ventas y ganancias)
    Route::get('/reportes', [ReporteController::class, 'index'])
        ->middleware('permiso:reportes.ver')
        ->name('reportes.index');

    // Auditoría
    Route::get('/auditoria', [AuditoriaController::class, 'index'])
        ->middleware('permiso:auditoria.ver')
        ->name('auditoria.index');
    Route::get('/auditoria/{auditoria}', [AuditoriaController::class, 'show'])
        ->middleware('permiso:auditoria.ver')
        ->name('auditoria.show');

    Route::post('/pos/ventas', [PosController::class, 'store'])
        ->middleware(['permiso:pos.usar', 'permiso:ventas.realizar'])
        ->name('pos.ventas.store');
    Route::post('/pos/ventas/pendientes', [PosController::class, 'pendiente'])
        ->middleware(['permiso:pos.usar', 'permiso:ventas.realizar'])
        ->name('pos.ventas.pendiente');
    Route::post('/pos/ventas/{venta}/confirmar-pago', [PosController::class, 'confirmarPago'])
        ->middleware(['permiso:pos.usar', 'permiso:ventas.realizar'])
        ->name('pos.ventas.confirmar');
    Route::post('/pos/ventas/{venta}/cancelar', [PosController::class, 'cancelarPendiente'])
        ->middleware(['permiso:pos.usar', 'permiso:ventas.realizar'])
        ->name('pos.ventas.cancelar');

    // Usuarios
    Route::get('/usuarios', [UsuarioController::class, 'index'])
        ->middleware('permiso:usuarios.gestionar')
        ->name('usuarios.index');
    Route::get('/usuarios/crear', [UsuarioController::class, 'create'])
        ->middleware('permiso:usuarios.gestionar')
        ->name('usuarios.create');
    Route::post('/usuarios', [UsuarioController::class, 'store'])
        ->middleware('permiso:usuarios.gestionar')
        ->name('usuarios.store');
    Route::get('/usuarios/{usuario}/editar', [UsuarioController::class, 'edit'])
        ->middleware('permiso:usuarios.gestionar')
        ->name('usuarios.edit');
    Route::put('/usuarios/{usuario}', [UsuarioController::class, 'update'])
        ->middleware('permiso:usuarios.gestionar')
        ->name('usuarios.update');
    Route::post('/usuarios/{usuario}/estado', [UsuarioController::class, 'toggleEstado'])
        ->middleware('permiso:usuarios.gestionar')
        ->name('usuarios.estado');

    // Caja
    Route::get('/caja', [CajaController::class, 'index'])
        ->middleware('permiso:cajas.usar')
        ->name('caja.index');
    Route::get('/caja/abrir', [CajaController::class, 'abrirFormulario'])
        ->middleware('permiso:cajas.usar')
        ->name('caja.abrir.form');
    Route::post('/caja/abrir', [CajaController::class, 'abrir'])
        ->middleware('permiso:cajas.usar')
        ->name('caja.abrir');
    Route::post('/caja/movimientos', [CajaController::class, 'movimiento'])
        ->middleware('permiso:cajas.usar')
        ->name('caja.movimiento');
    Route::get('/caja/cerrar', [CajaController::class, 'cerrarFormulario'])
        ->middleware('permiso:cajas.usar')
        ->name('caja.cerrar.form');
    Route::post('/caja/cerrar', [CajaController::class, 'cerrar'])
        ->middleware('permiso:cajas.usar')
        ->name('caja.cerrar');
});

require __DIR__.'/auth.php';
