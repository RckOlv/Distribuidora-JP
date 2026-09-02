<?php

namespace App\Services;

use App\Enums\EstadoImpresion;
use App\Enums\MedioPago;
use App\Enums\TipoMovimientoCaja;
use App\Enums\UnidadVenta;
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
    public function __construct(private CajaService $cajas) {}

    /**
     * @param  list<array{producto_id: int, cantidad: float|string}>  $items
     */
    public function registrar(Usuario $usuario, string $medioPago, array $items): Venta
    {
        if (! in_array($medioPago, array_column(MedioPago::cases(), 'value'), true)) {
            throw $this->error('medio_pago', 'El medio de pago seleccionado no es válido.');
        }

        if ($items === []) {
            throw $this->error('items', 'El carrito está vacío.');
        }

        $detalles = $this->prepararDetalles($items);

        return DB::transaction(function () use ($usuario, $medioPago, $detalles): Venta {
            // Regla de caja: no se vende sin una caja ABIERTA. Se bloquea la
            // fila para no registrar contra una caja que se está cerrando.
            $caja = $this->cajas->abiertaBloqueada();

            if ($caja === null) {
                throw $this->error('caja', 'No hay una caja abierta. Debés abrir una caja para comenzar a vender.');
            }

            $total = (float) array_sum(array_column($detalles, 'subtotal'));

            $venta = Venta::create([
                'usuario_id' => $usuario->id,
                'caja_id' => $caja->id,
                'medio_pago' => $medioPago,
                'total' => $total,
            ]);

            foreach ($detalles as $detalle) {
                DetalleVenta::create([
                    'venta_id' => $venta->id,
                    'producto_id' => $detalle['producto_id'],
                    'cantidad' => $detalle['cantidad'],
                    'precio_unitario' => $detalle['precio_unitario'],
                    'subtotal' => $detalle['subtotal'],
                ]);
            }

            // Movimiento de caja de la venta (automático, no manual).
            MovimientoCaja::create([
                'caja_id' => $caja->id,
                'venta_id' => $venta->id,
                'usuario_id' => $usuario->id,
                'tipo' => TipoMovimientoCaja::VENTA,
                'monto' => $total,
                'concepto' => 'Venta N° '.$this->numeroTicket($venta),
            ]);

            $this->crearTicketYCola($usuario, $venta);

            return $venta;
        });
    }

    /**
     * Crea el ticket de la venta y su trabajo de impresión pendiente.
     *
     * Todo ocurre dentro de la transacción de la venta: si falla, se revierte
     * la venta completa. La creación es idempotente (un ticket por venta y un
     * trabajo por ticket) aunque se reintente el flujo por error del cliente.
     */
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
            ['ticket_id' => $ticket->id],
            [
                'estado' => EstadoImpresion::PENDIENTE,
                'cantidad_intentos' => 0,
            ],
        );
    }

    /**
     * Número visible de ticket: estable, único y generado por el backend.
     * Se ancla al id de la venta (que nunca cambia); el constraint único de
     * tickets.venta_id garantiza que no haya colisiones.
     */
    private function numeroTicket(Venta $venta): string
    {
        return str_pad((string) $venta->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Snapshot completo del ticket en JSON: los montos provienen de la venta y
     * de los DetalleVenta ya persistidos (precio histórico), no del producto.
     * La información perecedera (nombre, unidad, datos del comercio) se
     * congela aquí al momento de emitir el ticket.
     */
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

        return json_encode([
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
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Revalida los productos contra la base de datos y calcula cada detalle
     * usando el precio vigente al momento de confirmar la venta.
     *
     * @param  list<array{producto_id: int, cantidad: float|string}>  $items
     * @return list<array{producto_id: int, cantidad: float, precio_unitario: float, subtotal: float}>
     */
    private function prepararDetalles(array $items): array
    {
        $detalles = [];

        foreach ($items as $indice => $item) {
            $producto = Producto::query()
                ->with(['categoria', 'precioVigente'])
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

            $detalles[] = [
                'producto_id' => $producto->id,
                'cantidad' => $cantidad,
                'precio_unitario' => $precioUnitario,
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

        if ($producto->unidad_medida !== UnidadVenta::KILOGRAMO && $cantidad !== floor($cantidad)) {
            $etiqueta = $producto->unidad_medida === UnidadVenta::UNIDAD ? 'unidad' : 'bolsa';

            throw $this->error(
                "{$campo}.cantidad",
                "La cantidad debe ser un número entero para la venta por {$etiqueta}.",
            );
        }

        return $cantidad;
    }

    private function error(string $campo, string $mensaje): ValidationException
    {
        return ValidationException::withMessages([$campo => $mensaje]);
    }
}
