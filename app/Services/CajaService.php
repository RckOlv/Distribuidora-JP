<?php

namespace App\Services;

use App\Enums\EstadoCaja;
use App\Enums\MedioPago;
use App\Enums\TipoMovimientoCaja;
use App\Models\Caja;
use App\Models\MovimientoCaja;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CajaService
{
    /**
     * Abre una caja si no hay ya una abierta. A lo sumo una ABIERTA.
     */
    public function abrir(Usuario $usuario, float $montoInicial): Caja
    {
        return DB::transaction(function () use ($usuario, $montoInicial): Caja {
            $yaAbierta = Caja::query()->abierta()->exists();
            if ($yaAbierta) {
                throw ValidationException::withMessages([
                    'monto_inicial' => 'Ya existe una caja abierta. Cerrá la actual antes de abrir otra.',
                ]);
            }

            return Caja::create([
                'usuario_abre_id' => $usuario->id,
                'estado' => EstadoCaja::ABIERTA,
                'monto_inicial' => $montoInicial,
                'abierta_en' => now(),
            ]);
        });
    }

    /**
     * Caja abierta actual (si existe).
     */
    public function actual(): ?Caja
    {
        return Caja::query()->abierta()->latest('id')->first();
    }

    /**
     * Registra un ingreso o egreso manual en una caja abierta.
     */
    public function registrarManual(
        Caja $caja,
        Usuario $usuario,
        TipoMovimientoCaja $tipo,
        float $monto,
        string $concepto,
    ): MovimientoCaja {
        if ($tipo !== TipoMovimientoCaja::INGRESO && $tipo !== TipoMovimientoCaja::EGRESO) {
            throw ValidationException::withMessages([
                'tipo' => 'El tipo de movimiento no es válido.',
            ]);
        }

        return DB::transaction(function () use ($caja, $usuario, $tipo, $monto, $concepto): MovimientoCaja {
            $caja = $this->cajaAbiertaBajoLock($caja);

            return MovimientoCaja::create([
                'caja_id' => $caja->id,
                'usuario_id' => $usuario->id,
                'tipo' => $tipo,
                'monto' => $monto,
                'concepto' => $concepto,
            ]);
        });
    }

    /**
     * Cierra una caja abierta de forma atómica y concurrente, guardando el
     * resultado histórico (contado/esperado/diferencia).
     *
     * @return array{efectivo_esperado: float, diferencia: float}
     */
    public function cerrar(Caja $caja, float $efectivoContado): array
    {
        return DB::transaction(function () use ($caja, $efectivoContado): array {
            $caja = $this->cajaAbiertaBajoLock($caja);

            $resumen = $this->resumen($caja);
            $efectivoEsperado = $resumen['efectivo_esperado'];
            $diferencia = round($efectivoContado - $efectivoEsperado, 2);

            $afectadas = Caja::query()
                ->whereKey($caja->id)
                ->where('estado', EstadoCaja::ABIERTA->value)
                ->update([
                    'estado' => EstadoCaja::CERRADA->value,
                    'cerrada_en' => now(),
                    'efectivo_contado' => $efectivoContado,
                    'efectivo_esperado' => $efectivoEsperado,
                    'diferencia' => $diferencia,
                ]);

            if ($afectadas === 0) {
                // Ya fue cerrada por otro request concurrente.
                throw ValidationException::withMessages([
                    'caja' => 'La caja ya está cerrada.',
                ]);
            }

            return [
                'efectivo_esperado' => $efectivoEsperado,
                'diferencia' => $diferencia,
            ];
        });
    }

    /**
     * Bloquea la fila de la caja y verifica que siga ABIERTA antes de operar.
     * El lockForUpdate serializa cierres y ventas contra la misma caja.
     */
    private function cajaAbiertaBajoLock(Caja $caja): Caja
    {
        $bloqueada = Caja::query()
            ->whereKey($caja->id)
            ->lockForUpdate()
            ->first();

        if ($bloqueada === null || ! $bloqueada->esAbierta()) {
            throw ValidationException::withMessages([
                'caja' => 'La caja no está abierta.',
            ]);
        }

        return $bloqueada;
    }

    /**
     * Resumen de una caja abierta o cerrada, calculado desde datos persistidos.
     *
     * @return array<string, mixed>
     */
    public function resumen(Caja $caja): array
    {
        $movimientos = MovimientoCaja::query()
            ->where('caja_id', $caja->id)
            ->with('venta:id,medio_pago')
            ->get();

        $montoInicial = (float) $caja->monto_inicial;

        $efectivoVentas = 0.0;
        $totalVentas = 0.0;
        $ingresos = 0.0;
        $egresos = 0.0;
        $porMedio = [];

        foreach ($movimientos as $mov) {
            $monto = (float) $mov->monto;

            if ($mov->tipo === TipoMovimientoCaja::VENTA) {
                $totalVentas += $monto;
                $medio = $mov->venta?->medio_pago?->value ?? 'VENTA';

                $porMedio[$medio] = ($porMedio[$medio] ?? 0.0) + $monto;

                // Solo el efectivo afecta al efectivo físico esperado.
                if ($mov->venta?->medio_pago === MedioPago::EFECTIVO) {
                    $efectivoVentas += $monto;
                }
            } elseif ($mov->tipo === TipoMovimientoCaja::INGRESO) {
                $ingresos += $monto;
            } elseif ($mov->tipo === TipoMovimientoCaja::EGRESO) {
                $egresos += $monto;
            }
        }

        $efectivoEsperado = round($montoInicial + $efectivoVentas + $ingresos - $egresos, 2);

        return [
            'id' => $caja->id,
            'estado' => $caja->estado->value,
            'estado_etiqueta' => $caja->estado->etiqueta(),
            'usuario_abrio' => $caja->usuarioAbrio?->name,
            'abierta_en' => $caja->abierta_en?->toIso8601String(),
            'cerrada_en' => $caja->cerrada_en?->toIso8601String(),
            'monto_inicial' => $montoInicial,
            'total_ventas' => round($totalVentas, 2),
            'cantidad_ventas' => $movimientos->where('tipo', TipoMovimientoCaja::VENTA)->count(),
            'ventas_efectivo' => round($porMedio[MedioPago::EFECTIVO->value] ?? 0, 2),
            'ventas_transferencia' => round($porMedio[MedioPago::TRANSFERENCIA->value] ?? 0, 2),
            'ventas_tarjeta' => round($porMedio[MedioPago::TARJETA->value] ?? 0, 2),
            'otros' => round(array_sum(array_diff_key($porMedio, array_flip(array_column(MedioPago::cases(), 'value')))), 2),
            'ingresos' => round($ingresos, 2),
            'egresos' => round($egresos, 2),
            'efectivo_esperado' => $efectivoEsperado,
            'efectivo_contado' => $caja->efectivo_contado === null ? null : (float) $caja->efectivo_contado,
            'diferencia' => $caja->diferencia === null ? null : (float) $caja->diferencia,
        ];
    }

    /**
     * Caja abierta bloqueada a partir de la caja actual, para uso transaccional
     * desde VentaService dentro de su propia transacción.
     *
     * Devuelve null si no hay caja abierta.
     */
    public function abiertaBloqueada(): ?Caja
    {
        return Caja::query()
            ->abierta()
            ->lockForUpdate()
            ->latest('id')
            ->first();
    }
}
