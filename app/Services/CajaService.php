<?php

namespace App\Services;

use App\Enums\AccionAuditoria;
use App\Enums\EstadoCaja;
use App\Enums\MedioPago;
use App\Enums\TipoMovimientoCaja;
use App\Models\Caja;
use App\Models\CajaFisica;
use App\Models\MovimientoCaja;
use App\Models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CajaService
{
    public function __construct(private AuditoriaService $auditoria) {}

    /**
     * Abre una sesión de caja asociada a una caja física.
     *
     * Reglas validadas a nivel de aplicación (las restricciones de base de
     * datos «cajas_fisica_abierta» y «cajas_usuario_abierta» son la garantía
     * final ante concurrencia):
     *  - el usuario no puede tener otra sesión ABIERTA;
     *  - la caja física debe existir y estar activa;
     *  - la caja física no puede tener otra sesión ABIERTA.
     *
     * Si no se indica \$cajaFisica, se elige automáticamente una caja física
     * activa sin sesión abierta (mientras el frontend no exponga el selector).
     *
     * @throws ValidationException
     */
    public function abrir(Usuario $usuario, float $montoInicial, ?CajaFisica $cajaFisica = null): Caja
    {
        return DB::transaction(function () use ($usuario, $montoInicial, $cajaFisica): Caja {
            if (Caja::query()->abierta()->where('usuario_abre_id', $usuario->id)->exists()) {
                throw $this->error(
                    'caja',
                    'Ya tenés una caja abierta. Cerrá la actual antes de abrir otra.'
                );
            }

            $cajaFisica ??= $this->elegirCajaFisicaLibre();

            if (! $cajaFisica->activa) {
                throw $this->error('caja', 'La caja física seleccionada no está activa.');
            }

            if (Caja::query()->abierta()->where('caja_fisica_id', $cajaFisica->id)->exists()) {
                throw $this->error(
                    'caja',
                    "La caja física «{$cajaFisica->nombre}» ya tiene una sesión abierta."
                );
            }

            try {
                $caja = Caja::create([
                    'caja_fisica_id' => $cajaFisica->id,
                    'usuario_abre_id' => $usuario->id,
                    'estado' => EstadoCaja::ABIERTA,
                    'monto_inicial' => $montoInicial,
                    'abierta_en' => now(),
                ]);
            } catch (QueryException $e) {
                if ($this->esConflitoDeCajaAbierta($e)) {
                    throw $this->error(
                        'caja',
                        "La caja física «{$cajaFisica->nombre}» ya tiene una sesión abierta."
                    );
                }

                throw $e;
            }

            $this->auditoria->registrar(
                AccionAuditoria::CAJA_ABIERTA,
                $usuario,
                'caja',
                $caja->id,
                "Se abrió la caja #{$caja->id} de «{$cajaFisica->nombre}» con \${$montoInicial}.",
                null,
                [
                    'caja_id' => $caja->id,
                    'caja_fisica_id' => $cajaFisica->id,
                    'caja_fisica_nombre' => $cajaFisica->nombre,
                    'usuario_abre_id' => $usuario->id,
                    'monto_inicial' => (float) $montoInicial,
                    'abierta_en' => $caja->abierta_en?->toIso8601String(),
                ],
            );

            return $caja;
        });
    }

    /**
     * Sesión de caja ABIERTA del usuario (si existe).
     */
    public function actualDelUsuario(Usuario $usuario): ?Caja
    {
        return Caja::query()
            ->abierta()
            ->where('usuario_abre_id', $usuario->id)
            ->latest('id')
            ->first();
    }

    /**
     * Sesión de caja ABIERTA del usuario, bloqueada para uso transaccional.
     * Devuelve null si el usuario no tiene una caja abierta.
     */
    public function abiertaBloqueada(Usuario $usuario): ?Caja
    {
        return Caja::query()
            ->abierta()
            ->where('usuario_abre_id', $usuario->id)
            ->lockForUpdate()
            ->latest('id')
            ->first();
    }

    /**
     * Registra un ingreso o egreso manual en la caja del usuario.
     *
     * El cajero solo puede operar su propia sesión; el dueño puede operar
     * cualquier sesión. El medio de pago es opcional para no romper la
     * compatibilidad con registros históricos sin medio asociado.
     */
    public function registrarManual(
        Caja $caja,
        Usuario $usuario,
        TipoMovimientoCaja $tipo,
        float $monto,
        string $concepto,
        ?MedioPago $medioPago = null,
    ): MovimientoCaja {
        if ($tipo !== TipoMovimientoCaja::INGRESO && $tipo !== TipoMovimientoCaja::EGRESO) {
            throw $this->error('tipo', 'El tipo de movimiento no es válido.');
        }

        return DB::transaction(function () use ($caja, $usuario, $tipo, $monto, $concepto, $medioPago): MovimientoCaja {
            $this->asegurarPuedeOperar($usuario, $caja);
            $caja = $this->cajaAbiertaBajoLock($caja);

            $movimiento = MovimientoCaja::create([
                'caja_id' => $caja->id,
                'usuario_id' => $usuario->id,
                'tipo' => $tipo,
                'monto' => $monto,
                'medio_pago' => $medioPago?->value,
                'concepto' => $concepto,
            ]);

            $accion = $tipo === TipoMovimientoCaja::INGRESO
                ? AccionAuditoria::INGRESO_CAJA
                : AccionAuditoria::EGRESO_CAJA;

            $this->auditoria->registrar(
                $accion,
                $usuario,
                'movimiento_caja',
                $movimiento->id,
                $tipo->etiqueta()." de \${$monto} en la caja #{$caja->id}: {$concepto}",
                null,
                [
                    'caja_id' => $caja->id,
                    'caja_fisica_id' => $caja->caja_fisica_id,
                    'movimiento_id' => $movimiento->id,
                    'tipo' => $tipo->value,
                    'monto' => (float) $monto,
                    'medio_pago' => $medioPago?->value,
                    'concepto' => $concepto,
                ],
            );

            return $movimiento;
        });
    }

    /**
     * Cierra la sesión de caja indicada de forma atómica y concurrente.
     *
     * El cajero solo puede cerrar su propia sesión; el dueño puede cerrar
     * cualquier sesión. Al cerrar solo se actualiza la propia fila (resultado
     * histórico); no se alteran ventas ni movimientos asociados.
     *
     * @return array{efectivo_esperado: float, diferencia: float}
     */
    public function cerrar(Usuario $usuario, Caja $caja, float $efectivoContado, ?string $observacion = null): array
    {
        return DB::transaction(function () use ($usuario, $caja, $efectivoContado, $observacion): array {
            $this->asegurarPuedeOperar($usuario, $caja);
            $caja = $this->cajaAbiertaBajoLock($caja);

            $resumen = $this->resumen($caja);
            $efectivoEsperado = $resumen['efectivo_esperado'];
            $diferencia = round($efectivoContado - $efectivoEsperado, 2);

            if (abs($diferencia) > 0.004 && trim((string) $observacion) === '') {
                throw ValidationException::withMessages([
                    'observacion' => 'Debés indicar la observación porque el efectivo contado difiere del esperado.',
                ]);
            }

            $observacionFinal = trim((string) $observacion) === '' ? null : trim((string) $observacion);

            $afectadas = Caja::query()
                ->whereKey($caja->id)
                ->where('estado', EstadoCaja::ABIERTA->value)
                ->update([
                    'estado' => EstadoCaja::CERRADA->value,
                    'cerrada_en' => now(),
                    'efectivo_contado' => $efectivoContado,
                    'efectivo_esperado' => $efectivoEsperado,
                    'diferencia' => $diferencia,
                    'observacion_cierre' => $observacionFinal,
                ]);

            if ($afectadas === 0) {
                throw $this->error('caja', 'La caja ya está cerrada.');
            }

            $cierre = $caja->fresh();

            $this->auditoria->registrar(
                AccionAuditoria::CAJA_CERRADA,
                $usuario,
                'caja',
                $caja->id,
                "Se cerró la caja #{$caja->id}.",
                ['caja_id' => $caja->id, 'estado_anterior' => EstadoCaja::ABIERTA->value],
                [
                    'caja_id' => $caja->id,
                    'caja_fisica_id' => $caja->caja_fisica_id,
                    'efectivo_esperado' => (float) $efectivoEsperado,
                    'efectivo_contado' => (float) $efectivoContado,
                    'diferencia' => $diferencia,
                    'observacion_cierre' => $observacionFinal,
                    'cerrada_en' => $cierre?->cerrada_en?->toIso8601String(),
                ],
            );

            return [
                'efectivo_esperado' => $efectivoEsperado,
                'diferencia' => $diferencia,
            ];
        });
    }

    /**
     * Resumen de una caja abierta o cerrada, calculado desde datos persistidos.
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
        $ingresosEfectivo = 0.0;
        $egresosEfectivo = 0.0;
        $porMedio = [];
        $movimientosLista = [];

        foreach ($movimientos as $mov) {
            $monto = (float) $mov->monto;

            if ($mov->tipo === TipoMovimientoCaja::VENTA) {
                $totalVentas += $monto;
                $medio = $mov->venta?->medio_pago?->value ?? 'VENTA';

                $porMedio[$medio] = ($porMedio[$medio] ?? 0.0) + $monto;

                if ($mov->venta?->medio_pago === MedioPago::EFECTIVO) {
                    $efectivoVentas += $monto;
                }
            } elseif ($mov->tipo === TipoMovimientoCaja::INGRESO) {
                $ingresos += $monto;

                // Un ingreso manual solo engrosa el efectivo físico del cajero
                // si fue en EFECTIVO (o sin medio asociado en registros antiguos).
                // Los ingresos por transferencia/tarjeta no entran a la caja física.
                if ($mov->medio_pago === null || $mov->medio_pago === MedioPago::EFECTIVO) {
                    $ingresosEfectivo += $monto;
                }
            } elseif ($mov->tipo === TipoMovimientoCaja::EGRESO) {
                $egresos += $monto;

                if ($mov->medio_pago === null || $mov->medio_pago === MedioPago::EFECTIVO) {
                    $egresosEfectivo += $monto;
                }
            }

            if ($mov->tipo !== TipoMovimientoCaja::VENTA) {
                $movimientosLista[] = [
                    'id' => $mov->id,
                    'tipo' => $mov->tipo->value,
                    'tipo_etiqueta' => $mov->tipo->etiqueta(),
                    'monto' => $monto,
                    'medio_pago' => $mov->medio_pago?->value ?? null,
                    'medio_pago_etiqueta' => $mov->medio_pago?->etiqueta() ?? null,
                    'concepto' => $mov->concepto,
                    'fecha' => $mov->created_at?->toIso8601String(),
                ];
            }
        }

        // El efectivo esperado solo contempla el dinero que debería estar en la
        // caja física: monto inicial + ventas en efectivo + ingresos/egresos
        // manuales en efectivo (medio nulo en históricos se trata como efectivo).
        $efectivoEsperado = round($montoInicial + $efectivoVentas + $ingresosEfectivo - $egresosEfectivo, 2);

        return [
            'id' => $caja->id,
            'estado' => $caja->estado->value,
            'estado_etiqueta' => $caja->estado->etiqueta(),
            'caja_fisica_id' => $caja->caja_fisica_id,
            'caja_fisica_nombre' => $caja->cajaFisica?->nombre,
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
            'observacion_cierre' => $caja->observacion_cierre,
            'movimientos' => array_reverse($movimientosLista),
        ];
    }

    /**
     * Elige una caja física activa que no tenga una sesión abierta.
     * Si todas estuvieran ocupadas, devuelve la primera activa.
     */
    private function elegirCajaFisicaLibre(): CajaFisica
    {
        $ocupadas = Caja::query()->abierta()->pluck('caja_fisica_id');

        $libre = CajaFisica::query()
            ->where('activa', true)
            ->whereNotIn('id', $ocupadas)
            ->oldest('id')
            ->first();

        return $libre ?? CajaFisica::query()->where('activa', true)->oldest('id')->firstOrFail();
    }

    /**
     * Verifica que el usuario pueda operar la sesión de caja indicada.
     * El dueño puede operar cualquier sesión; un cajero solo la propia.
     *
     * @throws ValidationException
     */
    public function asegurarPuedeOperar(Usuario $usuario, Caja $caja): void
    {
        if ($usuario->esDueno()) {
            return;
        }

        if ($caja->usuario_abre_id !== $usuario->id) {
            throw $this->error('caja', 'No tenés acceso a esta sesión de caja.');
        }
    }

    /**
     * Bloquea la fila de la caja y verifica que siga ABIERTA antes de operar.
     * El lockForUpdate serializa cierres y movimientos contra la misma caja.
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

    private function esConflitoDeCajaAbierta(QueryException $e): bool
    {
        return app()->isProduction() === false
            && str_contains(strtolower($e->getMessage()), 'unique');
    }

    private function error(string $campo, string $mensaje): ValidationException
    {
        return ValidationException::withMessages([$campo => $mensaje]);
    }
}
