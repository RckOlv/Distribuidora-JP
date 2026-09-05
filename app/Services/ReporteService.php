<?php

namespace App\Services;

use App\Enums\EstadoPagoVenta;
use App\Enums\MedioPago;
use App\Enums\TipoMovimientoCaja;
use App\Models\Caja;
use App\Models\MovimientoCaja;
use App\Models\Venta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resumen diario y reportes básicos.
 *
 * Centraliza las agregaciones SQL del módulo de reportes (evitando N+1) y
 * reutiliza CajaService para el resumen de caja, cuya fórmula ya existe:
 * monto inicial + ventas efectivo + ingresos − egresos = efectivo esperado.
 */
class ReporteService
{
    private const TOP_PRODUCTOS = 5;

    public function __construct(private CajaService $cajas) {}

    /**
     * Resumen completo del día indicado (fecha YYYY-MM-DD, timezone Laravel).
     *
     * Mantiene la compatibilidad con los reportes puntuales: un solo día es
     * un caso particular de rango con desde == hasta.
     *
     * @return array<string, mixed>
     */
    public function resumenDelDia(string $fecha): array
    {
        return $this->resumen($fecha, $fecha);
    }

    /**
     * Resumen completo del rango solicitado (desde/hasta YYYY-MM-DD,
     * timezone Laravel).
     *
     * La ganancia y el margen se calculan SOLO sobre las ventas con costo
     * conocido (costo histórico congelado al momento de la venta). Las ventas
     * sin costo se reportan por separado y no contaminan la ganancia.
     *
     * @return array<string, mixed>
     */
    public function resumen(string $desde, string $hasta): array
    {
        [$inicio, $fin] = $this->rango($desde, $hasta);

        $ventas = $this->agregarVentas($inicio, $fin);
        $costos = $this->agregarCostos($inicio, $fin);
        $desglose = $this->desglosePorMedio($inicio, $fin);

        $cantidadVentas = (int) ($ventas->cantidad ?? 0);
        $totalVentas = round((float) ($ventas->total ?? 0.0), 2);
        $conCosto = round((float) ($costos->subtotal_con_costo ?? 0.0), 2);
        $sinCosto = round((float) ($costos->subtotal_sin_costo ?? 0.0), 2);
        $costoTotal = round((float) ($costos->costo_total ?? 0.0), 2);

        $ganancia = $conCosto > 0 ? round($conCosto - $costoTotal, 2) : null;
        $margen = ($conCosto > 0 && $ganancia !== null)
            ? round(($ganancia / $conCosto) * 100, 2)
            : null;

        return [
            'fecha' => $desde,
            'desde' => $desde,
            'hasta' => $hasta,
            'ventas' => [
                'cantidad' => $cantidadVentas,
                'total' => $totalVentas,
                'por_medio' => $this->mediosFormateados($desglose),
            ],
            'ganancia' => [
                'total' => $totalVentas,
                'con_costo' => $conCosto,
                'sin_costo' => $sinCosto,
                'costo' => $costoTotal,
                'ganancia' => $ganancia,
                'margen' => $margen,
            ],
            'cajas' => $this->resumenCajas($inicio, $fin),
            'mas_vendidos' => $this->productosMasVendidos($inicio, $fin),
            'ventas_por_dia' => $this->ventasPorDia($inicio, $fin),
        ];
    }

    /**
     * Rango completo desde/hasta (inclusive), con la timezone de la app.
     *
     * El límite superior es exclusivo (hasta + 1 día) para que las consultas
     * cubran la última fecha por completo con created_at < fin.
     *
     * @return array{Carbon, Carbon}
     */
    private function rango(string $desde, string $hasta): array
    {
        $inicio = Carbon::parse($desde, config('app.timezone'))->startOfDay();
        $fin = Carbon::parse($hasta, config('app.timezone'))->startOfDay()->addDay();

        return [$inicio, $fin];
    }

    /**
     * Cantidad y total de ventas del rango.
     */
    private function agregarVentas(Carbon $inicio, Carbon $fin): object
    {
        return Venta::query()
            ->where('estado_pago', EstadoPagoVenta::PAGADA->value)
            ->where('created_at', '>=', $inicio)
            ->where('created_at', '<', $fin)
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(total), 0) as total')
            ->first();
    }

    /**
     * Costo y subtotales históricos de los detalles de venta del rango, con
     * una sola consulta SQL (sin N+1).
     */
    private function agregarCostos(Carbon $inicio, Carbon $fin): object
    {
        return DB::table('detalles_venta as dv')
            ->join('ventas as v', 'dv.venta_id', '=', 'v.id')
            ->where('v.estado_pago', EstadoPagoVenta::PAGADA->value)
            ->where('v.created_at', '>=', $inicio)
            ->where('v.created_at', '<', $fin)
            ->selectRaw('
                COALESCE(SUM(CASE WHEN dv.costo_unitario IS NOT NULL THEN dv.subtotal ELSE 0 END), 0) as subtotal_con_costo,
                COALESCE(SUM(CASE WHEN dv.costo_unitario IS NULL THEN dv.subtotal ELSE 0 END), 0) as subtotal_sin_costo,
                COALESCE(SUM(CASE WHEN dv.costo_unitario IS NOT NULL THEN dv.costo_unitario * dv.cantidad ELSE 0 END), 0) as costo_total
            ')
            ->first();
    }

    /**
     * Desglose de ventas por medio de pago real del rango.
     *
     * @return Collection<int, object>
     */
    private function desglosePorMedio(Carbon $inicio, Carbon $fin)
    {
        // El desglose real vive en pagos_venta (una fila por medio aplicado).
        // Las ventas históricas sin pagos_venta caen a ventas.medio_pago con el
        // total como monto. COUNT(DISTINCT ventas.id) evita contar una venta
        // mixta más de una vez por fila de pago.
        return Venta::query()
            ->leftJoin('pagos_venta as pv', 'pv.venta_id', '=', 'ventas.id')
            ->where('ventas.estado_pago', EstadoPagoVenta::PAGADA->value)
            ->where('ventas.created_at', '>=', $inicio)
            ->where('ventas.created_at', '<', $fin)
            ->selectRaw('
                COALESCE(pv.medio_pago, ventas.medio_pago) as medio_pago,
                COUNT(DISTINCT ventas.id) as cantidad,
                COALESCE(SUM(COALESCE(pv.monto, ventas.total)), 0) as total
            ')
            ->groupByRaw('COALESCE(pv.medio_pago, ventas.medio_pago)')
            ->get();
    }

    /**
     * Normaliza el desglose incluyendo los medios sin ventas (en cero).
     *
     * @param  Collection<int, object>  $desglose
     * @return list<array{medio: string, etiqueta: string, cantidad: int, total: float}>
     */
    private function mediosFormateados($desglose): array
    {
        $porValor = $desglose->keyBy('medio_pago');

        return collect(MedioPago::cases())
            ->map(function (MedioPago $medio) use ($porValor) {
                $fila = $porValor->get($medio->value);

                return [
                    'medio' => $medio->value,
                    'etiqueta' => $medio->etiqueta(),
                    'cantidad' => (int) ($fila->cantidad ?? 0),
                    'total' => round((float) ($fila->total ?? 0.0), 2),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Total vendido por cada día del rango, con una sola consulta SQL.
     *
     * Incluye TODOS los días del rango (incluso sin ventas, en cero) y en
     * orden cronológico, para que el gráfico no dependa del frontend.
     *
     * @return list<array{fecha: string, total: float}>
     */
    private function ventasPorDia(Carbon $inicio, Carbon $fin): array
    {
        $porFecha = Venta::query()
            ->where('estado_pago', EstadoPagoVenta::PAGADA->value)
            ->where('created_at', '>=', $inicio)
            ->where('created_at', '<', $fin)
            ->selectRaw('DATE(created_at) as dia, COALESCE(SUM(total), 0) as total')
            ->groupBy('dia')
            ->orderBy('dia')
            ->get()
            ->keyBy('dia');

        $serie = [];

        for ($dia = $inicio->copy(); $dia->lt($fin); $dia->addDay()) {
            $clave = $dia->format('Y-m-d');
            $fila = $porFecha->get($clave);

            $serie[] = [
                'fecha' => $clave,
                'total' => round((float) ($fila->total ?? 0.0), 2),
            ];
        }

        return $serie;
    }

    /**
     * Productos más vendidos del rango (por cantidad sumada).
     *
     * Incluye el costo histórico cuando está disponible para que el dueño
     * pueda ver la ganancia por producto sin re-consultar la base.
     *
     * @return list<array<string, mixed>>
     */
    private function productosMasVendidos(Carbon $inicio, Carbon $fin): array
    {
        return DB::table('detalles_venta as dv')
            ->join('ventas as v', 'dv.venta_id', '=', 'v.id')
            ->leftJoin('productos as p', 'dv.producto_id', '=', 'p.id')
            ->where('v.estado_pago', EstadoPagoVenta::PAGADA->value)
            ->where('v.created_at', '>=', $inicio)
            ->where('v.created_at', '<', $fin)
            ->selectRaw('
                dv.producto_id as producto_id,
                p.nombre as nombre,
                SUM(dv.cantidad) as cantidad,
                SUM(dv.subtotal) as subtotal,
                COALESCE(SUM(CASE WHEN dv.costo_unitario IS NOT NULL THEN dv.costo_unitario * dv.cantidad ELSE 0 END), 0) as costo
            ')
            ->groupBy('dv.producto_id', 'p.nombre')
            ->orderByDesc('cantidad')
            ->orderByDesc('subtotal')
            ->limit(self::TOP_PRODUCTOS)
            ->get()
            ->map(fn ($fila) => [
                'producto_id' => (int) $fila->producto_id,
                'nombre' => $fila->nombre !== null ? (string) $fila->nombre : "Producto #{$fila->producto_id}",
                'cantidad' => round((float) $fila->cantidad, 3),
                'total' => round((float) $fila->subtotal, 2),
                'costo' => round((float) $fila->costo, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * Resumen de caja usando la fórmula ya existente (CajaService::resumen).
     *
     * En reportes, cada «caja» listada es en realidad una SESIÓN (una fila de la
     * tabla `cajas`, es decir una apertura). Una caja física puede tener varias
     * sesiones en un período. La sesión pertenece al período si tuvo ventas
     * (según la fecha real de la venta) o movimientos manuales creados en el
     * período. El agregado cuenta sesiones (cantidad_sesiones) y suma su
     * efectivo esperado: no debe interpretarse como «cantidad de cajas físicas»
     * ni como el efectivo físico actual de una caja en particular.
     *
     * @return array<string, mixed>
     */
    private function resumenCajas(Carbon $inicio, Carbon $fin): array
    {
        $porVentas = Venta::query()
            ->where('estado_pago', EstadoPagoVenta::PAGADA->value)
            ->where('created_at', '>=', $inicio)
            ->where('created_at', '<', $fin)
            ->whereNotNull('caja_id')
            ->distinct()
            ->pluck('caja_id');

        $porMovimiento = MovimientoCaja::query()
            ->whereIn('tipo', [TipoMovimientoCaja::INGRESO->value, TipoMovimientoCaja::EGRESO->value])
            ->where('created_at', '>=', $inicio)
            ->where('created_at', '<', $fin)
            ->selectRaw('DISTINCT caja_id')
            ->pluck('caja_id');

        $cajaIds = $porVentas->merge($porMovimiento)->unique()->values();

        $cajas = [];
        $agregado = [
            'cantidad_sesiones' => 0,
            'monto_inicial' => 0.0,
            'total_ventas' => 0.0,
            'cantidad_ventas' => 0,
            'ventas_efectivo' => 0.0,
            'ingresos' => 0.0,
            'egresos' => 0.0,
            'efectivo_esperado' => 0.0,
        ];

        foreach ($cajaIds as $cajaId) {
            $caja = Caja::query()->with('usuarioAbrio')->find($cajaId);

            if ($caja === null) {
                continue;
            }

            $resumen = $this->cajas->resumen($caja);

            $cajas[] = [
                'id' => $resumen['id'],
                'estado' => $resumen['estado'],
                'estado_etiqueta' => $resumen['estado_etiqueta'],
                'usuario_abrio' => $resumen['usuario_abrio'],
                'abierta_en' => $resumen['abierta_en'],
                'cerrada_en' => $resumen['cerrada_en'],
                'monto_inicial' => $resumen['monto_inicial'],
                'total_ventas' => $resumen['total_ventas'],
                'cantidad_ventas' => $resumen['cantidad_ventas'],
                'ventas_efectivo' => $resumen['ventas_efectivo'],
                'ventas_tarjeta' => $resumen['ventas_tarjeta'],
                'ventas_transferencia' => $resumen['ventas_transferencia'],
                'otros' => $resumen['otros'],
                'ingresos' => $resumen['ingresos'],
                'egresos' => $resumen['egresos'],
                'efectivo_esperado' => $resumen['efectivo_esperado'],
            ];

            $agregado['cantidad_sesiones']++;
            $agregado['monto_inicial'] += $resumen['monto_inicial'];
            $agregado['total_ventas'] += $resumen['total_ventas'];
            $agregado['cantidad_ventas'] += $resumen['cantidad_ventas'];
            $agregado['ventas_efectivo'] += $resumen['ventas_efectivo'];
            $agregado['ingresos'] += $resumen['ingresos'];
            $agregado['egresos'] += $resumen['egresos'];
        }

        // La fórmula de caja (ya existente) aplicada sobre los totales del día:
        // monto inicial + ventas efectivo + ingresos − egresos = efectivo esperado.
        $agregado['efectivo_esperado'] = round(
            $agregado['monto_inicial']
                + $agregado['ventas_efectivo']
                + $agregado['ingresos']
                - $agregado['egresos'],
            2,
        );

        $agregado['monto_inicial'] = round($agregado['monto_inicial'], 2);
        $agregado['total_ventas'] = round($agregado['total_ventas'], 2);
        $agregado['ventas_efectivo'] = round($agregado['ventas_efectivo'], 2);
        $agregado['ingresos'] = round($agregado['ingresos'], 2);
        $agregado['egresos'] = round($agregado['egresos'], 2);

        return [
            'cajas' => $cajas,
            'agregado' => $agregado,
        ];
    }
}
