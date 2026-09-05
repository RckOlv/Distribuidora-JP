<?php

namespace App\Http\Controllers;

use App\Enums\EstadoPagoVenta;
use App\Enums\MedioPago;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\Ticket;
use App\Models\Venta;
use App\Services\CajaService;
use App\Services\VentaService;
use App\Support\Permisos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    /**
     * Punto de venta: categorías activas, productos vendibles y las ventas
     * pendientes de pago de la caja actual.
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

        $caja = $cajas->actualDelUsuario($request->user());

        $pendientes = $caja !== null
            ? Venta::query()
                ->with('detalles')
                ->where('caja_id', $caja->id)
                ->where('estado_pago', EstadoPagoVenta::PENDIENTE_PAGO->value)
                ->orderByDesc('id')
                ->get()
                ->map(fn (Venta $venta) => $this->formatoPendiente($venta))
                ->values()
            : collect();

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
            'caja_abierta' => $caja !== null,
            'caja_sesion_id' => $caja?->id ?? null,
            'caja_fisica_nombre' => $caja?->cajaFisica?->nombre ?? null,
            'puede_abrir_caja' => $request->user()?->can(Permisos::CAJAS_USAR) ?? false,
            'ventas_pendientes' => $pendientes,
        ]);
    }

    /**
     * Confirma una venta recalculando precios y totales en el backend.
     *
     * Registra una venta completa y definitiva (movimiento de caja, ticket,
     * impresión y auditoría). Se usa para efectivo. Las ventas por
     * transferencia/tarjeta del frontend usan el flujo de pago pendiente.
     */
    public function store(Request $request, VentaService $ventas): RedirectResponse
    {
        $datos = $this->validarItems($request);

        $efectivoRecibido = $datos['efectivo_recibido'] ?? null;

        $pagos = $datos['pagos'] ?? null;

        if ($pagos !== null) {
            $venta = $ventas->registrarConPagos(
                $request->user(),
                $pagos,
                $datos['items'],
                $efectivoRecibido !== null ? (float) $efectivoRecibido : null,
            );
        } else {
            $venta = $ventas->registrar(
                $request->user(),
                $datos['medio_pago'],
                $datos['items'],
                $efectivoRecibido !== null ? (float) $efectivoRecibido : null,
            );
        }

        return $this->respuestaVenta($venta);
    }

    /**
     * Crea una venta PENDIENTE de pago (transferencia/tarjeta).
     *
     * No contabiliza la venta como definitiva: solo persiste la venta y sus
     * detalles. Hasta confirmar el pago no hay movimiento de caja, ticket,
     * impresión ni auditoría. Exige al menos un pago por transferencia o
     * tarjeta (una venta totalmente pagada en el momento no debe quedar
     * pendiente).
     */
    public function pendiente(Request $request, VentaService $ventas): RedirectResponse
    {
        $datos = $this->validarItems($request, ['TRANSFERENCIA', 'TARJETA']);

        $pagos = $datos['pagos'] ?? null;

        if ($pagos !== null) {
            $this->exigirPagoElectronico($pagos);

            $venta = $ventas->registrarPendienteConPagos(
                $request->user(),
                $pagos,
                $datos['items'],
                isset($datos['efectivo_recibido']) ? (float) $datos['efectivo_recibido'] : null,
            );
        } else {
            // Flujo legado: medio único ya restringido a transferencia/tarjeta.
            $venta = $ventas->registrarPendiente(
                $request->user(),
                $datos['medio_pago'],
                $datos['items'],
            );
        }

        return redirect()->route('pos.index')
            ->with('success', 'Venta registrada como pendiente de pago.'
                .' Total: $'.number_format((float) $venta->total, 2, ',', '.').'. Confirma el pago para finalizarla.');
    }

    /**
     * Confirma el pago de una venta pendiente, completándola.
     */
    public function confirmarPago(Request $request, Venta $venta, VentaService $ventas): RedirectResponse
    {
        try {
            $ventas->confirmarPago($request->user(), $venta);
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors());
        }

        $venta->loadMissing('ticket');

        $mensaje = 'Venta confirmada correctamente. Pago por $'
            .number_format((float) $venta->total, 2, ',', '.').'.';

        if ($venta->ticket instanceof Ticket) {
            $mensaje .= " Ticket N° {$venta->ticket->numero} en cola de impresión.";
        }

        return redirect()->route('pos.index')
            ->with('success', $mensaje);
    }

    /**
     * Cancela una venta pendiente de pago.
     */
    public function cancelarPendiente(Request $request, Venta $venta, VentaService $ventas): RedirectResponse
    {
        try {
            $ventas->cancelarPendiente($venta);
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors());
        }

        return redirect()->route('pos.index')
            ->with('success', 'Venta cancelada. La venta pendiente se eliminó y el stock fue devuelto.');
    }

    /**
     * Valida los items de un pedido del POS. Acepta `pagos.*` (desglose por
     * medio) o el legacy `medio_pago` único. Si se envía `pagos` no se exige
     * `medio_pago`.
     *
     * @param  list<string>  $mediosPermitidos
     * @return array{medio_pago?: string, pagos?: array<int, array{medio_pago: string, monto: float}>, items: array<int, array{producto_id: int, cantidad: mixed}>}
     */
    private function validarItems(Request $request, array $mediosPermitidos = []): array
    {
        $reglaMedio = empty($mediosPermitidos)
            ? ['sometimes', 'required_without:pagos', Rule::enum(MedioPago::class)]
            : ['sometimes', 'required_without:pagos', Rule::in($mediosPermitidos)];

        return $request->validate([
            'medio_pago' => $reglaMedio,
            'pagos' => ['sometimes', 'array', 'min:1'],
            'pagos.*.medio_pago' => ['required', Rule::enum(MedioPago::class)],
            // decimal(12,2): más de 0, hasta 2 decimales y dentro del rango.
            'pagos.*.monto' => [
                'required',
                'numeric',
                'decimal:0,2',
                'min:0.01',
                'max:9999999999.99',
            ],
            'items' => ['required', 'array', 'min:1'],
            'items.*.producto_id' => ['required', 'integer', 'exists:productos,id'],
            'items.*.cantidad' => ['required', 'numeric', 'min:0.01'],
            'efectivo_recibido' => [
                'nullable',
                'numeric',
                'decimal:0,2',
                'min:0.01',
                'max:9999999999.99',
            ],
        ]);
    }

    /**
     * Exige al menos un pago por transferencia o tarjeta en ventas pendientes
     * con desglose de pagos (una venta totalmente abonada no queda pendiente).
     *
     * @param  array<int, array{medio_pago: string, monto: float}>  $pagos
     *
     * @throws ValidationException
     */
    private function exigirPagoElectronico(array $pagos): void
    {
        $tieneElectronico = count(array_filter(
            $pagos,
            fn (array $pago): bool => in_array(
                $pago['medio_pago'] ?? null,
                [MedioPago::TRANSFERENCIA->value, MedioPago::TARJETA->value],
                true,
            ),
        )) > 0;

        if (! $tieneElectronico) {
            throw ValidationException::withMessages([
                'pagos' => 'Una venta pendiente debe incluir al menos un pago por transferencia o tarjeta.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatoPendiente(Venta $venta): array
    {
        return [
            'id' => $venta->id,
            'numero' => str_pad((string) $venta->id, 6, '0', STR_PAD_LEFT),
            'fecha' => $venta->created_at?->toIso8601String(),
            'medio_pago' => $venta->medio_pago->value,
            'medio_pago_etiqueta' => $venta->medio_pago->etiqueta(),
            'pagos' => $venta->pagosNormalizados(),
            'total' => (float) $venta->total,
            'cantidad_items' => $venta->detalles->count(),
        ];
    }

    /**
     * Redirige con el mensaje de confirmación de una venta completa.
     */
    private function respuestaVenta(Venta $venta): RedirectResponse
    {
        $venta->loadMissing('ticket');

        $mensaje = 'Venta registrada correctamente por $'
            .number_format((float) $venta->total, 2, ',', '.').'.';

        if ($venta->ticket instanceof Ticket) {
            $mensaje .= " Ticket N° {$venta->ticket->numero} en cola de impresión.";
        }

        return redirect()->route('pos.index')
            ->with('success', $mensaje);
    }
}
