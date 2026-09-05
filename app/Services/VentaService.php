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
use App\Models\PagoVenta;
use App\Models\Producto;
use App\Models\Ticket;
use App\Models\TrabajoImpresion;
use App\Models\Usuario;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VentaService
{
    /** Tope del tipo decimal(12,2): hasta 9.999.999.999,99 (10 enteros + 2 decimales). */
    private const MONTO_MAX = 9999999999.99;

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

        $pagos = $this->desdeMedioUnico($medioPago, $total);
        [$recibidoOk, $vuelto] = $this->resolverEfectivo($pagos, $total, $efectivoRecibido, exigirRecibido: true);

        return $this->guardar($usuario, $pagos, $detalles, EstadoPagoVenta::PAGADA, $recibidoOk, $vuelto, definitiva: true);
    }

    /**
     * Registra una venta completa y definitiva desglosada en múltiples medios
     * de pago. Cada medio cubre una parte del total a través de la tabla
     * pagos_venta; la suma de los montos debe coincidir con el total.
     *
     * El efectivo recibido solo se pide en una venta 100% en efectivo (debe
     * cubrir el total y el vuelto se calcula sobre el efectivo aplicado). En
     * ventas mixtas con parte en efectivo no se pide recibido ni se calcula
     * vuelto: cada medio cubre directamente su parte del total.
     *
     * @param  list<array{medio_pago: string, monto: float|int|string}>  $pagos
     * @param  list<array{producto_id: int, cantidad: float|string}>  $items
     */
    public function registrarConPagos(
        Usuario $usuario,
        array $pagos,
        array $items,
        ?float $efectivoRecibido = null,
    ): Venta {
        $detalles = $this->prepararDetalles($this->validarItems($items));

        $total = (float) array_sum(array_column($detalles, 'subtotal'));

        $pagosOk = $this->validarPagos($pagos, $total);
        [$recibidoOk, $vuelto] = $this->resolverEfectivo($pagosOk, $total, $efectivoRecibido, exigirRecibido: true);

        return $this->guardar($usuario, $pagosOk, $detalles, EstadoPagoVenta::PAGADA, $recibidoOk, $vuelto, definitiva: true);
    }

    /**
     * Crea una venta en estado PENDIENTE_PAGO para transferencia/tarjeta.
     *
     * Mientras está pendiente NO se contabiliza como venta definitiva: no crea
     * movimiento de caja, ticket, trabajo de impresión ni auditoría. Solo
     * persiste la venta y sus detalles (y sus pagos); al confirmar el pago se
     * completan.
     *
     * @param  list<array{producto_id: int, cantidad: float|string}>  $items
     */
    public function registrarPendiente(Usuario $usuario, string $medioPago, array $items): Venta
    {
        $detalles = $this->prepararDetalles($this->validarMedioYItems($medioPago, $items));

        $total = (float) array_sum(array_column($detalles, 'subtotal'));

        $pagos = $this->desdeMedioUnico($medioPago, $total);
        [$recibidoOk, $vuelto] = $this->resolverEfectivo($pagos, $total, null, exigirRecibido: false);

        return $this->guardar($usuario, $pagos, $detalles, EstadoPagoVenta::PENDIENTE_PAGO, $recibidoOk, $vuelto, definitiva: false);
    }

    /**
     * Crea una venta pendiente de pago desglosada en múltiples medios. El
     * efectivo recibido es siempre opcional: en pendientes hay al menos un
     * medio electrónico, así que nunca se pide recibido ni se calcula vuelto.
     *
     * @param  list<array{medio_pago: string, monto: float|int|string}>  $pagos
     * @param  list<array{producto_id: int, cantidad: float|string}>  $items
     */
    public function registrarPendienteConPagos(
        Usuario $usuario,
        array $pagos,
        array $items,
        ?float $efectivoRecibido = null,
    ): Venta {
        $detalles = $this->prepararDetalles($this->validarItems($items));

        $total = (float) array_sum(array_column($detalles, 'subtotal'));

        $pagosOk = $this->validarPagos($pagos, $total);
        [$recibidoOk, $vuelto] = $this->resolverEfectivo($pagosOk, $total, $efectivoRecibido, exigirRecibido: false);

        return $this->guardar($usuario, $pagosOk, $detalles, EstadoPagoVenta::PENDIENTE_PAGO, $recibidoOk, $vuelto, definitiva: false);
    }

    /**
     * Valida items y persiste una venta (definitiva o pendiente). En el caso
     * definitivo completa la venta (movimiento, ticket, impresión, auditoría).
     *
     * @param  list<array{medio_pago: string, monto: float}>  $pagos
     * @param  list<array{producto_id: int, cantidad: float, precio_unitario: float, costo_unitario: float|null, subtotal: float}>  $detalles
     */
    private function guardar(
        Usuario $usuario,
        array $pagos,
        array $detalles,
        EstadoPagoVenta $estado,
        ?float $recibidoOk,
        ?float $vuelto,
        bool $definitiva,
    ): Venta {
        return DB::transaction(function () use ($usuario, $pagos, $detalles, $estado, $recibidoOk, $vuelto, $definitiva): Venta {
            $caja = $this->cajas->abiertaBloqueada($usuario);

            if ($caja === null) {
                throw $this->error('caja', 'No hay una caja abierta. Debés abrir una caja para comenzar a vender.');
            }

            $venta = $this->crear($usuario, $caja, $pagos, $detalles, $estado, $recibidoOk, $vuelto);

            if ($definitiva) {
                $this->finalizar($usuario, $venta, $caja);
            }

            return $venta;
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
     * Normaliza el desglose de pagos y valida que cubra exactamente el total.
     *
     * @param  list<array{medio_pago: string, monto: float|int|string}>  $pagos
     * @return list<array{medio_pago: string, monto: float}>
     */
    private function validarPagos(array $pagos, float $total): array
    {
        if ($pagos === []) {
            throw $this->error('pagos', 'Debés indicar al menos un medio de pago.');
        }

        $medios = [];

        foreach (array_values($pagos) as $indice => $pago) {
            $campo = "pagos.{$indice}";

            $medio = $pago['medio_pago'] ?? null;

            if (! in_array($medio, array_column(MedioPago::cases(), 'value'), true)) {
                throw $this->error("{$campo}.medio_pago", 'El medio de pago seleccionado no es válido.');
            }

            if (in_array($medio, $medios, true)) {
                throw $this->error("{$campo}.medio_pago", 'Cada medio de pago puede usarse una sola vez por venta.');
            }

            $monto = $pago['monto'] ?? null;

            if (! $this->esMontoValido($monto)) {
                throw $this->error("{$campo}.monto", 'Ingresá un importe de pago válido (más de 0 y hasta 2 decimales).');
            }

            $medios[] = $medio;
        }

        $normalizados = array_map(
            fn (array $pago): array => [
                'medio_pago' => $pago['medio_pago'],
                'monto' => round((float) $pago['monto'], 2),
            ],
            array_values($pagos),
        );

        $suma = round(array_sum(array_column($normalizados, 'monto')), 2);

        if ($suma !== $total) {
            throw $this->error(
                'pagos',
                "La suma de los pagos ($ {$suma}) no coincide con el total de la venta ($ {$total}).",
            );
        }

        return $normalizados;
    }

    /**
     * Valida un importe monetario del POS: numérico, mayor que cero, dentro
     * del rango de decimal(12,2) y con hasta 2 decimales.
     *
     * El backend no confía en la validación del frontend: cualquier venta que
     * llegue con montos fuera de estos límites se rechaza aquí.
     */
    private function esMontoValido(mixed $valor): bool
    {
        if (! is_numeric($valor)) {
            return false;
        }

        $numero = (float) $valor;

        if ($numero <= 0 || $numero > self::MONTO_MAX) {
            return false;
        }

        return $this->tieneHastaDosDecimales($valor);
    }

    private function tieneHastaDosDecimales(mixed $valor): bool
    {
        if (is_float($valor) && stripos((string) $valor, 'e') !== false) {
            $texto = rtrim(rtrim(sprintf('%.8F', $valor), '0'), '.');
        } else {
            $texto = (string) $valor;
        }

        $posicion = strpos($texto, '.');

        if ($posicion === false) {
            return true;
        }

        return strlen(substr($texto, $posicion + 1)) <= 2;
    }

    /**
     * Descifra el efectivo recibido/vuelto para el desglose de pagos.
     *
     * Solo una venta 100% en efectivo pide "efectivo recibido" (y calcula el
     * vuelto como recibido − efectivo aplicado). En ventas mixtas (efectivo +
     * otro medio) o 100% electrónicas el cliente paga exactamente el monto de
     * cada medio: sin recibido, recibido y vuelto quedan null.
     *
     * Si se envía un recibido se valida contra el efectivo aplicado (no contra
     * el total). Con exigirRecibido=true (venta definitiva) se exige el
     * recibido solo cuando toda la venta es en efectivo; en pendientes queda
     * siempre opcional.
     *
     * @param  list<array{medio_pago: string, monto: float}>  $pagos
     * @return array{0: float|null, 1: float|null}
     */
    private function resolverEfectivo(array $pagos, float $total, ?float $recibido, bool $exigirRecibido): array
    {
        $efectivo = (float) collect($pagos)
            ->where('medio_pago', MedioPago::EFECTIVO->value)
            ->sum('monto');

        if ($efectivo == 0) {
            return [null, null];
        }

        $esSoloEfectivo = abs($efectivo - $total) < 0.005;

        if ($recibido === null) {
            if ($exigirRecibido && $esSoloEfectivo) {
                throw $this->error(
                    'efectivo_recibido',
                    'El efectivo recibido debe cubrir el total de la venta.'
                );
            }

            return [null, null];
        }

        if (! $this->esMontoValido($recibido)) {
            throw $this->error(
                'efectivo_recibido',
                'Ingresá un efectivo recibido válido (más de 0 y hasta 2 decimales).'
            );
        }

        if ($recibido < $efectivo) {
            throw $this->error(
                'efectivo_recibido',
                'El efectivo recibido debe cubrir el efectivo aplicado a la venta.'
            );
        }

        return [$recibido, round($recibido - $efectivo, 2)];
    }

    /**
     * Convierte un medio único (flujo histórico) al desglose equivalente.
     *
     * @return list<array{medio_pago: string, monto: float}>
     */
    private function desdeMedioUnico(string $medioPago, float $total): array
    {
        return [['medio_pago' => $medioPago, 'monto' => $total]];
    }

    /**
     * Valor legado para ventas.medio_pago: EFECTIVO si algún pago es efectivo,
     * si no el primer medio (siempre un valor válido del enum).
     *
     * @param  list<array{medio_pago: string, monto: float}>  $pagos
     */
    private function medioLegacy(array $pagos): string
    {
        foreach ($pagos as $pago) {
            if ($pago['medio_pago'] === MedioPago::EFECTIVO->value) {
                return MedioPago::EFECTIVO->value;
            }
        }

        return $pagos[0]['medio_pago'];
    }

    /**
     * Valida solo los items (compartido por los flujos con desglose de pagos).
     *
     * @param  list<array{producto_id: int, cantidad: float|string}>  $items
     * @return list<array{producto_id: int, cantidad: float|string}>
     */
    private function validarItems(array $items): array
    {
        if ($items === []) {
            throw $this->error('items', 'El carrito está vacío.');
        }

        return $items;
    }

    /**
     * Persiste la fila de venta y sus detalles. Devuelve la venta creada.
     *
     * @param  list<array{producto_id: int, cantidad: float, precio_unitario: float, costo_unitario: float|null, subtotal: float}>  $detalles
     */
    private function crear(
        Usuario $usuario,
        Caja $caja,
        array $pagos,
        array $detalles,
        EstadoPagoVenta $estadoPago,
        ?float $efectivoRecibido = null,
        ?float $vuelto = null,
    ): Venta {
        $total = (float) array_sum(array_column($detalles, 'subtotal'));

        $venta = Venta::create([
            'usuario_id' => $usuario->id,
            'caja_id' => $caja->id,
            'medio_pago' => $this->medioLegacy($pagos),
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

        foreach ($pagos as $pago) {
            PagoVenta::create([
                'venta_id' => $venta->id,
                'medio_pago' => $pago['medio_pago'],
                'monto' => $pago['monto'],
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
        $pagos = $venta->pagos()->orderBy('id')->get()->all();

        if ($pagos === []) {
            $pagos = [[
                'medio' => $venta->medio_pago,
                'monto' => (float) $venta->total,
            ]];
        }

        // Un movimiento de caja VENTA por cada medio de pago, con su medio.
        foreach ($pagos as $pago) {
            $medio = $pago['medio'] ?? $pago->medio_pago;
            $monto = (float) ($pago['monto'] ?? $pago->monto);

            MovimientoCaja::create([
                'caja_id' => $caja->id,
                'venta_id' => $venta->id,
                'usuario_id' => $usuario->id,
                'tipo' => TipoMovimientoCaja::VENTA,
                'monto' => $monto,
                'medio_pago' => $medio->value,
                'concepto' => 'Venta N° '.$this->numeroTicket($venta),
            ]);
        }

        $this->crearTicketYCola($usuario, $venta);

        $movimientos = $venta->pagos()->get()->map(fn (PagoVenta $pago) => [
            'medio_pago' => $pago->medio_pago->value,
            'etiqueta' => $pago->medio_pago->etiqueta(),
            'monto' => (float) $pago->monto,
        ])->values()->all();

        if ($movimientos !== []) {
            $pagosAuditoria = $movimientos;
        } else {
            $pagosAuditoria = [[
                'medio_pago' => $venta->medio_pago->value,
                'etiqueta' => $venta->medio_pago->etiqueta(),
                'monto' => (float) $venta->total,
            ]];
        }

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
                'pagos' => $pagosAuditoria,
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
            'pagos' => array_map(
                fn (PagoVenta $pago) => [
                    'medio_pago' => $pago->medio_pago->value,
                    'etiqueta' => $pago->medio_pago->etiqueta(),
                    'monto' => (string) $pago->monto,
                ],
                $venta->pagos()->orderBy('id')->get()->all(),
            ),
            'total' => (string) $venta->total,
            'comercio' => [
                'nombre' => (string) config('comercio.nombre'),
                'direccion' => (string) config('comercio.direccion'),
                'telefono' => (string) config('comercio.telefono'),
                'leyenda' => (string) config('comercio.leyenda'),
            ],
            'detalles' => $detalles,
        ];

        // Snapshot histórico sin pagos_venta: se omite el desglose y el bridge
        // cae al medio_pago único.
        if ($ticket['pagos'] === []) {
            unset($ticket['pagos']);
        }

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
