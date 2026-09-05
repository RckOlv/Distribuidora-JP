<?php

namespace App\Http\Controllers;

use App\Enums\EstadoPagoVenta;
use App\Enums\MedioPago;
use App\Enums\TipoTrabajoImpresion;
use App\Http\Requests\VentasHistorialRequest;
use App\Models\Usuario;
use App\Models\Venta;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
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
            ->where('estado_pago', EstadoPagoVenta::PAGADA->value)
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
                // El medio puede estar en pagos_venta (ventas nuevas) o, en
                // históricos, en ventas.medio_pago.
                $query->where(function (Builder $query) use ($medioPago) {
                    $query->where('ventas.medio_pago', $medioPago)
                        ->orWhereHas('pagos', fn ($query) => $query->where('medio_pago', $medioPago));
                });
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
            'ticket.trabajos.usuario:id,name',
            'detalles.producto:id,nombre,unidad_medida',
        ]);

        $numero = $venta->ticket?->numero ?? $this->numeroVenta($venta);
        $impresion = $venta->ticket?->trabajos->firstWhere('tipo', TipoTrabajoImpresion::VENTA->value);
        $ticketContenido = $venta->ticket?->contenido
            ? json_decode($venta->ticket->contenido, true)
            : null;

        $detalles = $venta->detalles->map(fn ($detalle) => [
            'producto_id' => $detalle->producto_id,
            'nombre' => $detalle->producto?->nombre ?? 'Producto eliminado',
            'unidad_medida' => $detalle->producto?->unidad_medida?->value,
            'cantidad' => (float) $detalle->cantidad,
            'precio_unitario' => (float) $detalle->precio_unitario,
            'subtotal' => (float) $detalle->subtotal,
        ]);

        $trabajos = $venta->ticket?->trabajos->map(fn ($trabajo) => [
            'id' => $trabajo->id,
            'tipo' => $trabajo->tipo->value,
            'tipo_etiqueta' => $trabajo->tipo->etiqueta(),
            'estado' => $trabajo->estado->value,
            'estado_etiqueta' => $trabajo->estado->etiqueta(),
            'usuario' => $trabajo->usuario?->name,
            'motivo' => $trabajo->motivo,
            'solicitado_en' => $trabajo->created_at?->toIso8601String(),
            'impreso_en' => $trabajo->impreso_en?->toIso8601String(),
        ])->values()->all() ?? [];

        return Inertia::render('Ventas/Show', [
            'venta' => [
                'id' => $venta->id,
                'numero' => $numero,
                'fecha' => $venta->created_at?->toIso8601String(),
                'usuario' => $venta->usuario?->name,
                'caja_id' => $venta->caja_id,
                'medio_pago' => $venta->medio_pago->value,
                'medio_pago_etiqueta' => $venta->medio_pago->etiqueta(),
                'pagos' => $venta->pagosNormalizados(),
                'efectivo_recibido' => $venta->efectivo_recibido === null ? null : (float) $venta->efectivo_recibido,
                'vuelto' => $venta->vuelto === null ? null : (float) $venta->vuelto,
                'total' => (float) $venta->total,
                'ticket_numero' => $venta->ticket?->numero,
                'ticket' => $venta->ticket
                    ? [
                        'numero' => $venta->ticket->numero,
                        'estado_impresion' => $impresion?->estado?->value,
                        'estado_impresion_etiqueta' => $impresion?->estado?->etiqueta(),
                        'contenido' => $ticketContenido,
                    ]
                    : null,
            ],
            'detalles' => $detalles,
            'trabajos' => $trabajos,
        ]);
    }

    /**
     * Descarga el ticket de la venta en PDF con formato térmico de 80 mm.
     *
     * Es solamente una exportación/visualización del ticket histórico: usa el
     * snapshot inmutable almacenado en {@see Ticket::$contenido}, no modifica la
     * venta ni el ticket.
     */
    public function ticketPdf(Venta $venta): \Illuminate\Http\Response
    {
        $venta->load(['ticket', 'caja.cajaFisica']);

        $ticket = $venta->ticket;
        $contenido = $ticket?->contenido
            ? json_decode($ticket->contenido, true)
            : null;

        if ($ticket === null || ! is_array($contenido)) {
            abort(404, 'Esta venta no tiene ticket para descargar.');
        }

        $contenido['caja_fisica'] = $venta->caja?->cajaFisica?->nombre;

        $anchoPuntos = 226.77; // 80 mm
        $altoPuntos = $this->altoTicketPuntos($contenido);

        $pdf = Pdf::loadView('pdf.ticket', [
            'ticket' => $contenido,
        ])->setPaper([0, 0, $anchoPuntos, $altoPuntos]);

        return $pdf->download('ticket-'.$contenido['numero'].'.pdf');
    }

    /**
     * Estima la altura en puntos que necesita el ticket según su contenido.
     * El tamaño crece con la cantidad de líneas de detalle; se usa un ancho fijo
     * de 80 mm y margen mínimo, sin recurrir a hojas A4.
     *
     * @param  array<string, mixed>  $contenido
     */
    private function altoTicketPuntos(array $contenido): float
    {
        $detalles = (array) ($contenido['detalles'] ?? []);
        $comercio = (array) ($contenido['comercio'] ?? []);
        $pagos = $contenido['pagos'] ?? null;

        $alto = 20.0; // margen superior

        // Encabezado de comercio (nombre + dirección + teléfono).
        $alto += 30;
        if (! empty($comercio['direccion'])) {
            $alto += 14;
        }
        if (! empty($comercio['telefono'])) {
            $alto += 14;
        }

        // Número, fecha, caja física, usuario y medio de pago.
        $alto += 60;

        // Encabezado de la tabla y una línea por detalle.
        $alto += 22;
        $alto += max(1, count($detalles)) * 20;

        // Total, recibido/vuelto (efectivo) y pago.
        $alto += 38;
        if (array_key_exists('efectivo_recibido', $contenido) && ($contenido['efectivo_recibido'] ?? null) !== null) {
            $alto += 16;
        }
        if (array_key_exists('vuelto', $contenido) && ($contenido['vuelto'] ?? null) !== null) {
            $alto += 16;
        }
        if (is_array($pagos) && $pagos !== []) {
            $alto += count($pagos) * 18;
        } elseif (! empty($contenido['medio_pago'])) {
            $alto += 20;
        }

        // Leyenda de cierre y margen inferior.
        if (! empty($comercio['leyenda'])) {
            $alto += 26;
        }
        $alto += 22;

        // Factor de seguridad para que el contenido no corte en una segunda página.
        return $alto * 1.15;
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
            'pagos' => $venta->pagosNormalizados(),
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
