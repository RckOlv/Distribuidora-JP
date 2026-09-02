<?php

namespace App\Http\Controllers;

use App\Enums\MedioPago;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\Ticket;
use App\Services\CajaService;
use App\Services\VentaService;
use App\Support\Permisos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    /**
     * Punto de venta: categorías activas y productos vendibles.
     *
     * El POS no usa las rutas administrativas de productos: obtiene aquí su
     * propio catálogo (activos y con categoría activa) con su precio vigente.
     */
    public function index(Request $request, CajaService $cajas): Response
    {
        $categorias = Categoria::query()
            ->where('activa', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        $productos = Producto::query()
            ->with(['categoria:id,nombre', 'precioVigente'])
            ->where('activo', true)
            ->whereHas('categoria', fn ($query) => $query->where('activa', true))
            ->get();

        return Inertia::render('Pos/Index', [
            'categorias' => $categorias,
            'productos' => $productos->map(fn (Producto $producto) => [
                'id' => $producto->id,
                'nombre' => $producto->nombre,
                'codigo' => $producto->codigo,
                'unidad_medida' => $producto->unidad_medida->value,
                'categoria_id' => $producto->categoria_id,
                'categoria_nombre' => $producto->categoria?->nombre,
                'imagen_url' => $producto->imagen_url,
                'precio' => $producto->precioVigente?->monto,
            ])->values(),
            'medios_pago' => collect(MedioPago::cases())
                ->map(fn (MedioPago $medio) => [
                    'valor' => $medio->value,
                    'etiqueta' => $medio->etiqueta(),
                ])
                ->values(),
            'caja_abierta' => $cajas->actual() !== null,
            'puede_abrir_caja' => $request->user()?->can(Permisos::CAJAS_USAR) ?? false,
        ]);
    }

    /**
     * Confirma una venta recalculando precios y totales en el backend.
     */
    public function store(Request $request, VentaService $ventas): RedirectResponse
    {
        $datos = $request->validate([
            'medio_pago' => ['required', Rule::enum(MedioPago::class)],
            'items' => ['required', 'array', 'min:1'],
            'items.*.producto_id' => ['required', 'integer', 'exists:productos,id'],
            'items.*.cantidad' => ['required', 'numeric', 'min:0.01'],
        ]);

        $venta = $ventas->registrar(
            $request->user(),
            $datos['medio_pago'],
            $datos['items'],
        );

        $venta->loadMissing('ticket');

        $mensaje = 'Venta registrada correctamente por $'.number_format((float) $venta->total, 2, ',', '.').'.';

        if ($venta->ticket instanceof Ticket) {
            $mensaje .= " Ticket N° {$venta->ticket->numero} en cola de impresión.";
        }

        return redirect()->route('pos.index')
            ->with('success', $mensaje);
    }
}
