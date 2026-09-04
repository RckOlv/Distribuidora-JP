<?php

namespace App\Services;

use App\Enums\AccionAuditoria;
use App\Enums\EstadoImpresion;
use App\Enums\EstadoPagoVenta;
use App\Enums\MedioPago;
use App\Enums\TipoMovimientoCaja;
use App\Enums\TipoTrabajoImpresion;
use App\Enums\UnidadVenta;
use App\Models\Caja;
use App\Models\DetalleVenta;
use App\Models\MovimientoCaja;
use App\Models\Producto;
use App\Models\Ticket;
use App\Models\TrabajoImpresion;
use App\Models\Usuario;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VentaService
{
    public function __construct(
        private CajaService $cajas,
        private AuditoriaService $auditoria,
    ) {}

    /**
     * Registra una venta completa y definitiva (medio efectivo o pago ya
     * confirmado). Crea la venta, sus detalles, el movimiento de caja, el
     * ticket, el trabajo de impresión y la auditoría, todo en una transacción.
     *
     * En efectivo se exige el monto efectivo recibido (debe cubrir el total);
     * el vuelto se calcula y se guarda para mostrarlo en el ticket.
     *
     * @param  list<array{producto_id: int, cantidad: float|string}>  $items
     */
    public function registrar(
        Usuario $usuario,
        string $medioPago,
        array $items,
        ?float $efectivoRecibido = null,
    ): Venta {
        $detalles = $this->prepararDetalles($this->validarMedioYItems($medioPago, $items));

        $total = (float) array_sum(array_column($detalles, 'subtotal'));
        $efectivoRecibidoOk = $this->validarRecibido($medioPago, $total, $efectivoRecibido);
        $vuelto = $efectivoRecibidoOk === null ? null : round($efectivoRecibidoOk - $total, 2);

        return DB::transaction(function () use ($usuario, $medioPago, $detalles, $efectivoRecibidoOk, $vuelto): Venta {
            $caja = $this->cajas->abiertaBloqueada($usuario);

            if ($caja === null) {
                throw $this->error('caja', 'No hay una caja abierta. Debés abrir una caja para comenzar a vender.');
            }

            $venta = $this->crear($usuario, $caja, $medioPago, $detalles, EstadoPagoVenta::PAGADA, $efectivoRecibidoOk, $vuelto);

            $this->finalizar($usuario, $venta, $caja);

            return $venta;
        });
    }

    /**
     * Crea una venta en estado PENDIENTE_PAGO para transferencia/tarjeta.
     *
     * Mientras está pendiente NO se contabiliza como venta definitiva: no crea
     * movimiento de caja, ticket, trabajo de impresión ni auditoría. Solo
     * persiste la venta y sus detalles; al confirmar el pago se completan.
     *
     * @param  list<array{producto_id: int, cantidad: float|string}>  $items
     */
    public function registrarPendiente(Usuario $usuario, string $medioPago, array $items): Venta
    {
        $detalles = $this->prepararDetalles($this->validarMedioYItems($medioPago, $items));

        return DB::transaction(function () use ($usuario, $medioPago, $detalles): Venta {
            $caja = $this->cajas->abiertaBloqueada($usuario);

            if ($caja === null) {
                throw $this->error('caja', 'No hay una caja abierta. Debés abrir una caja para comenzar a vender.');
            }

            return $this->crear($usuario, $caja, $medioPago, $detalles, EstadoPagoVenta::PENDIENTE_PAGO);
        });
    }

    /**
     * Confirma el pago de una venta pendiente, completándola (movimiento de
     * caja, ticket, impresión y auditoría) de forma transaccional e
     * idempotente. Un doble clic o un reintento no generan duplicados.
     */
    public function confirmarPago(Usuario $usuario, Venta $venta): Venta
    {
        return DB::transaction(function () use ($usuario, $venta): Venta {
            $bloqueada = Venta::query()->whereKey($venta->id)->lockForUpdate()->first();

            if ($bloqueada === null) {
                throw $this->error('venta', 'La venta no existe.');
            }

            // Idempotente: si ya está pagada no se hace nada (evita duplicar).
            if ($bloqueada->esPagada()) {
                return $bloqueada;
            }

            if ($bloqueada->estado_pago !== EstadoPagoVenta::PENDIENTE_PAGO) {
                throw $this->error('venta', 'La venta no está pendiente de pago.');
            }

            // La confirmación opera SIEMPRE sobre la caja original de la venta,
            // no sobre la "caja actual" (que con múltiples cajas sería ambigua).
            $caja = Caja::query()->whereKey($bloqueada->caja_id)->lockForUpdate()->first();

            if ($caja === null) {
                throw $this->error('caja', 'La caja original de esta venta ya no existe.');
            }

            if (! $caja->esAbierta()) {
                throw $this->error(
                    'caja',
                    'La caja original de esta venta está cerrada; no se puede confirmar el pago.'
                );
            }

            // Un cajero solo puede confirmar ventas de su propia caja; el dueño
            // puede confirmar sobre cualquier caja.
            $this->cajas->asegurarPuedeOperar($usuario, $caja);

            $bloqueada->update(['estado_pago' => EstadoPagoVenta::PAGADA]);

            $this->finalizar($usuario, $bloqueada, $caja);

            return $bloqueada->fresh();
        });
    }

    /**
     * Cancela una venta pendiente de pago (la elimina junto con sus detalles).
     * Solo se permite si aún está pendiente; una venta pagada no se toca.
     */
    public function cancelarPendiente(Venta $venta): void
    {
        DB::transaction(function () use ($venta): void {
            $bloqueada = Venta::query()->whereKey($venta->id)->lockForUpdate()->first();

            if ($bloqueada === null) {
                throw $this->error('venta', 'La venta no existe.');
            }

            if ($bloqueada->esPagada()) {
                throw $this->error('venta', 'No se puede cancelar una venta ya pagada.');
            }

            $bloqueada->delete();
        });
    }

    /**
     * Valida el efectivo recibido para una venta en efectivo.
     * Devuelve el monto confirmado (recibido) o null si el medio no es efectivo.
     *
     * @throws ValidationException
     */
    private function validarRecibido(string $medioPago, float $total, ?float $efectivoRecibido): ?float
    {
        if ($medioPago !== MedioPago::EFECTIVO->value) {
            return null;
        }

        if ($efectivoRecibido === null || $efectivoRecibido < $total || $efectivoRecibido < 0) {
            throw $this->error('efectivo_recibido', 'El efectivo recibido debe cubrir el total de la venta.');
        }

        return $efectivoRecibido;
    }

    /**
     * Persiste la fila de venta y sus detalles. Devuelve la venta creada.
     *
     * @param  list<array{producto_id: int, cantidad: float, precio_unitario: float, costo_unitario: float|null, subtotal: float}>  $detalles
     */
    private function crear(
        Usuario $usuario,
        Caja $caja,
        string $medioPago,
        array $detalles,
        EstadoPagoVenta $estadoPago,
        ?float $efectivoRecibido = null,
        ?float $vuelto = null,
    ): Venta {
        $total = (float) array_sum(array_column($detalles, 'subtotal'));

        $venta = Venta::create([
            'usuario_id' => $usuario->id,
            'caja_id' => $caja->id,
            'medio_pago' => $medioPago,
            'total' => $total,
            'efectivo_recibido' => $efectivoRecibido,
            'vuelto' => $vuelto,
            'estado_pago' => $estadoPago->value,
        ]);

        foreach ($detalles as $detalle) {
            DetalleVenta::create([
                'venta_id' => $venta->id,
                'producto_id' => $detalle['producto_id'],
                'cantidad' => $detalle['cantidad'],
                'precio_unitario' => $detalle['precio_unitario'],
                'costo_unitario' => $detalle['costo_unitario'],
                'subtotal' => $detalle['subtotal'],
            ]);
        }

        return $venta;
    }

    /**
     * Completa una venta para que quede definitiva y contabilizada: crea el
     * movimiento de caja, el ticket, el trabajo de impresión y la auditoría.
     * Debe ejecutarse dentro de la transacción de la venta.
     */
    private function finalizar(Usuario $usuario, Venta $venta, Caja $caja): void
    {
        MovimientoCaja::create([
            'caja_id' => $caja->id,
            'venta_id' => $venta->id,
            'usuario_id' => $usuario->id,
            'tipo' => TipoMovimientoCaja::VENTA,
            'monto' => (float) $venta->total,
            'concepto' => 'Venta N° '.$this->numeroTicket($venta),
        ]);

        $this->crearTicketYCola($usuario, $venta);

        $this->auditoria->registrar(
            AccionAuditoria::VENTA_REALIZADA,
            $usuario,
            'venta',
            $venta->id,
            'Venta N° '.$this->numeroTicket($venta)." total de \${$venta->total}.",
            null,
            [
                'venta_id' => $venta->id,
                'numero' => $this->numeroTicket($venta),
                'caja_id' => $caja->id,
                'usuario_id' => $usuario->id,
                'medio_pago' => $venta->medio_pago->value,
                'total' => (float) $venta->total,
            ],
        );
    }

    /**
     * Valida y devuelve los items para el flujo de venta.
     *
     * @param  list<array{producto_id: int, cantidad: float|string}>  $items
     * @return list<array{producto_id: int, cantidad: float|string}>
     */
    private function validarMedioYItems(string $medioPago, array $items): array
    {
        if (! in_array($medioPago, array_column(MedioPago::cases(), 'value'), true)) {
            throw $this->error('medio_pago', 'El medio de pago seleccionado no es válido.');
        }

        if ($items === []) {
            throw $this->error('items', 'El carrito está vacío.');
        }

        return $items;
    }

    /**
     * @param  list<array{producto_id: int, cantidad: float|string}>  $items
     */
    private function prepararDetalles(array $items): array
    {
        $detalles = [];

        foreach ($items as $indice => $item) {
            $producto = Producto::query()
                ->with(['categoria', 'precioVigente', 'costoVigente'])
                ->find((int) $item['producto_id']);

            $campo = "items.{$indice}";

            if ($producto === null) {
                throw $this->error("{$campo}.producto_id", 'El producto seleccionado no existe.');
            }

            if (! $producto->activo || ! $producto->categoria?->activa) {
                throw $this->error(
                    "{$campo}.producto_id",
                    "El producto «{$producto->nombre}» no está disponible para la venta.",
                );
            }

            $precio = $producto->precioVigente;

            if ($precio === null) {
                throw $this->error(
                    "{$campo}.producto_id",
                    "El producto «{$producto->nombre}» no tiene precio vigente.",
                );
            }

            $cantidad = $this->validarCantidad($producto, $item['cantidad'], $campo);

            $precioUnitario = (float) $precio->monto;
            $subtotal = round($precioUnitario * $cantidad, 2);

            $costoUnitario = $producto->costoVigente === null
                ? null
                : (float) $producto->costoVigente->precio;

            $detalles[] = [
                'producto_id' => $producto->id,
                'cantidad' => $cantidad,
                'precio_unitario' => $precioUnitario,
                'costo_unitario' => $costoUnitario,
                'subtotal' => $subtotal,
            ];
        }

        return $detalles;
    }

    private function validarCantidad(Producto $producto, mixed $cantidad, string $campo): float
    {
        if (! is_numeric($cantidad)) {
            throw $this->error("{$campo}.cantidad", 'La cantidad debe ser un número.');
        }

        $cantidad = (float) $cantidad;

        if ($cantidad <= 0) {
            throw $this->error("{$campo}.cantidad", 'La cantidad debe ser mayor que cero.');
        }

        if ($producto->unidad_medida === UnidadVenta::KILOGRAMO) {
            if ($this->decimales($cantidad) > 3) {
                throw $this->error(
                    "{$campo}.cantidad",
                    'La cantidad en kilogramos admite hasta 3 decimales.',
                );
            }
        } elseif ($cantidad !== floor($cantidad)) {
            $etiqueta = $producto->unidad_medida === UnidadVenta::UNIDAD ? 'unidad' : 'bolsa';

            throw $this->error(
                "{$campo}.cantidad",
                "La cantidad debe ser un número entero para la venta por {$etiqueta}.",
            );
        }

        return $cantidad;
    }

    /**
     * Cantidad de decimales del valor recibido (ignora ceros al final).
     */
    private function decimales(mixed $valor): int
    {
        if (is_int($valor)) {
            return 0;
        }

        $texto = rtrim(rtrim((string) $valor, '0'), '.');

        if (! str_contains($texto, '.') && ! str_contains($texto, ',')) {
            return 0;
        }

        $parte = str_contains($texto, '.') ? explode('.', $texto)[1] : explode(',', $texto)[1];

        return strlen($parte);
    }

    private function crearTicketYCola(Usuario $usuario, Venta $venta): void
    {
        $ticket = Ticket::firstOrCreate(
            ['venta_id' => $venta->id],
            [
                'numero' => $this->numeroTicket($venta),
                'contenido' => $this->contenidoTicket($usuario, $venta),
                'impreso_en' => null,
            ],
        );

        TrabajoImpresion::firstOrCreate(
            ['ticket_id' => $ticket->id, 'tipo' => TipoTrabajoImpresion::VENTA->value],
            [
                'usuario_id' => $usuario->id,
                'estado' => EstadoImpresion::PENDIENTE,
                'cantidad_intentos' => 0,
            ],
        );
    }

    private function numeroTicket(Venta $venta): string
    {
        return str_pad((string) $venta->id, 6, '0', STR_PAD_LEFT);
    }

    private function contenidoTicket(Usuario $usuario, Venta $venta): string
    {
        $detalles = $venta->detalles()->with('producto')->get()
            ->map(fn (DetalleVenta $detalle) => [
                'nombre' => $detalle->producto?->nombre ?? "Producto #{$detalle->producto_id}",
                'unidad_medida' => $detalle->producto?->unidad_medida->value ?? 'UNIDAD',
                'cantidad' => (string) $detalle->cantidad,
                'precio_unitario' => (string) $detalle->precio_unitario,
                'subtotal' => (string) $detalle->subtotal,
            ])
            ->values()
            ->all();

        $ticket = [
            'numero' => $this->numeroTicket($venta),
            'fecha' => $venta->created_at?->setTimezone(config('app.timezone'))->format('d/m/Y H:i'),
            'usuario' => $usuario->name,
            'medio_pago' => $venta->medio_pago?->etiqueta(),
            'total' => (string) $venta->total,
            'comercio' => [
                'nombre' => (string) config('comercio.nombre'),
                'direccion' => (string) config('comercio.direccion'),
                'telefono' => (string) config('comercio.telefono'),
                'leyenda' => (string) config('comercio.leyenda'),
            ],
            'detalles' => $detalles,
        ];

        // El monto recibido y el vuelto solo figuran en ventas en efectivo;
        // en históricos sin registro se omiten del ticket.
        if ($venta->medio_pago === MedioPago::EFECTIVO && $venta->efectivo_recibido !== null) {
            $ticket['efectivo_recibido'] = (string) $venta->efectivo_recibido;
            $ticket['vuelto'] = $venta->vuelto === null ? null : (string) $venta->vuelto;
        }

        return json_encode($ticket, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function error(string $campo, string $mensaje): ValidationException
    {
        return ValidationException::withMessages([$campo => $mensaje]);
    }
}
