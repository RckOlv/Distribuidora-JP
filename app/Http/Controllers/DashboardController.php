<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use App\Models\Producto;
use App\Support\Permisos;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Muestra el panel principal de la aplicación.
     *
     * Los totales solo se calculan si el usuario posee el permiso; el acceso
     * exclusivo del Dueño no se resuelve únicamente en el frontend.
     */
    public function index(Request $request): Response
    {
        $usuario = $request->user();

        return Inertia::render('Dashboard', [
            'totales' => [
                'productos' => $usuario->can(Permisos::PRODUCTOS_VER)
                    ? Producto::where('activo', true)->count()
                    : null,
                'categorias' => $usuario->can(Permisos::CATEGORIAS_VER)
                    ? Categoria::where('activa', true)->count()
                    : null,
            ],
        ]);
    }
}
