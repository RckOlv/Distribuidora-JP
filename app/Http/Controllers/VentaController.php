<?php

namespace App\Http\Controllers;

use App\Enums\MedioPago;
use App\Http\Requests\VentasHistorialRequest;
use App\Models\Usuario;
use App\Models\Venta;
use Inertia\Inertia;
use Inertia\Response;

class VentaController extends Controller
{
    /**
     * Historial de ventas: listado paginado con filtros servidor-side.
     *
     * Solo lectura. Nunca expone ni permite modificar los datos de una venta.
     */
    public function index(VentasHistorialRequest $request): Response
    {
        $datos = $request->validated();
        $usuarioId = ($datos['usuario_id'] ?? null) ?: null;
        $numero = trim((string) ($datos['numero'] ?? ''));
        $medioPago = $datos['medio_pago'] ?? '';

        $ventas = Venta::query()
            ->with([
                'usuario:id,name',
                'caja',
                'ticket:id,venta_id,numero',
                'ticket.trabajoImpresion:estado',
            ])
            ->when($this->fechaDesde($datos['fecha_desde'] ?? null), function ($query, $fecha) {
                $query->whereDate('ventas.created_at', '>=', $fecha);
            })
            ->when($this->fechaHasta($datos['fecha_hasta'] ?? null), function ($query, $fecha) {
                $query->whereDate('ventas.created_at', '<=', $fecha);
            })
            ->when($numero !== '', function ($query) use ($numero) {
                $query->where('ventas.id', (int) $numero);
            })
            ->when($usuarioId !== null, function ($query) use ($usuarioId) {
                $query->where('ventas.usuario_id', $usuarioId);
            })
            ->when($medioPago !== '', function ($query) use ($medioPago) {
                $query->where('ventas.medio_pago', $medioPago);
            })
            ->orderByDesc('ventas.created_at')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Venta $venta) => $this->formatoLista($venta));

        $filtros = [
            'fecha_desde' => $datos['fecha_desde'] ?? null,
            'fecha_hasta' => $datos['fecha_hasta'] ?? null,
            'numero' => $numero,
            'usuario_id' => $usuarioId,
            'medio_pago' => $medioPago,
        ];

        return Inertia::render('Ventas/Index', [
            'ventas' => $ventas,
            'usuarios' => Usuario::query()->orderBy('name')->get(['id', 'name']),
            'medios_pago' => collect(MedioPago::cases())
                ->map(fn (MedioPago $medio) => ['valor' => $medio->value, 'etiqueta' => $medio->etiqueta()])
                ->values(),
            'filtros' => $filtros,
        ]);
    }

    /**
     * Detalle de una venta con sus productos históricos.
     *
     * Usa exactamente los precios snapshot del momento de la venta.
     */
    public function show(Venta $venta): Response
    {
        $venta->load([
            'usuario:id,name',
            'caja',
            'ticket',
            'ticket.trabajoImpresion',
            'detalles.producto:id,nombre,unidad_medida',
        ]);

        $numero = $venta->ticket?->numero ?? $this->numeroVenta($venta);
        $impresion = $venta->ticket?->trabajoImpresion;

        $detalles = $venta->detalles->map(fn ($detalle) => [
            'producto_id' => $detalle->producto_id,
            'nombre' => $detalle->producto?->nombre ?? 'Producto eliminado',
            'unidad_medida' => $detalle->producto?->unidad_medida?->value,
            'cantidad' => (float) $detalle->cantidad,
            'precio_unitario' => (float) $detalle->precio_unitario,
            'subtotal' => (float) $detalle->subtotal,
        ]);

        return Inertia::render('Ventas/Show', [
            'venta' => [
                'id' => $venta->id,
                'numero' => $numero,
                'fecha' => $venta->created_at?->toIso8601String(),
                'usuario' => $venta->usuario?->name,
                'caja_id' => $venta->caja_id,
                'medio_pago' => $venta->medio_pago->value,
                'medio_pago_etiqueta' => $venta->medio_pago->etiqueta(),
                'total' => (float) $venta->total,
                'ticket_numero' => $venta->ticket?->numero,
                'ticket' => $venta->ticket
                    ? [
                        'numero' => $venta->ticket->numero,
                        'estado_impresion' => $impresion?->estado?->value,
                        'estado_impresion_etiqueta' => $impresion?->estado?->etiqueta(),
                    ]
                    : null,
            ],
            'detalles' => $detalles,
        ]);
    }

    /**
     * Formatea una venta para la tabla del historial.
     *
     * @return array<string, mixed>
     */
    private function formatoLista(Venta $venta): array
    {
        $impresion = $venta->ticket?->trabajoImpresion;

        return [
            'id' => $venta->id,
            'numero' => $venta->ticket?->numero ?? $this->numeroVenta($venta),
            'fecha' => $venta->created_at?->toIso8601String(),
            'usuario' => $venta->usuario?->name,
            'medio_pago' => $venta->medio_pago->value,
            'medio_pago_etiqueta' => $venta->medio_pago->etiqueta(),
            'caja_id' => $venta->caja_id,
            'total' => (float) $venta->total,
            'estado_impresion' => $impresion?->estado?->value,
            'estado_impresion_etiqueta' => $impresion?->estado?->etiqueta(),
            'puede_ver' => true,
        ];
    }

    private function numeroVenta(Venta $venta): string
    {
        return str_pad((string) $venta->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Fecha de inicio del rango (nullable si vacía).
     */
    private function fechaDesde(?string $fecha): ?string
    {
        if ($fecha === null || $fecha === '') {
            return null;
        }

        return (string) $fecha;
    }

    /**
     * Fecha de fin del rango, inclusive hasta el final del día.
     */
    private function fechaHasta(?string $fecha): ?string
    {
        if ($fecha === null || $fecha === '') {
            return null;
        }

        return (string) $fecha;
    }
}
