<?php

namespace App\Http\Controllers;

use App\Enums\TipoMovimientoCaja;
use App\Http\Requests\AbrirCajaRequest;
use App\Http\Requests\CerrarCajaRequest;
use App\Http\Requests\MovimientoCajaRequest;
use App\Models\Caja;
use App\Services\CajaService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CajaController extends Controller
{
    public function __construct(private CajaService $cajas) {}

    /**
     * Estado de la caja actual: resumen si está abierta, o pantalla de
     * "sin caja abierta" si no.
     */
    public function index(): Response
    {
        $caja = $this->cajas->actual();

        return Inertia::render('Caja/Index', [
            'caja_abierta' => $caja !== null,
            'resumen' => $caja !== null ? $this->cajas->resumen($caja) : null,
        ]);
    }

    /**
     * Formulario para abrir una caja (solo si no hay una abierta).
     */
    public function abrirFormulario(): Response
    {
        return Inertia::render('Caja/Abrir', [
            'hay_caja_abierta' => $this->cajas->actual() !== null,
        ]);
    }

    /**
     * Abre una caja con el monto inicial.
     */
    public function abrir(AbrirCajaRequest $request): RedirectResponse
    {
        $this->cajas->abrir(
            $request->user(),
            (float) $request->validated('monto_inicial'),
        );

        return redirect()->route('caja.index')
            ->with('success', 'Caja abierta correctamente.');
    }

    /**
     * Registra un ingreso o egreso manual en la caja abierta actual.
     */
    public function movimiento(MovimientoCajaRequest $request): RedirectResponse
    {
        $datos = $request->validated();

        $caja = $this->cajas->actual();

        if ($caja === null) {
            return redirect()->route('caja.index')
                ->with('success', 'No hay una caja abierta para registrar movimientos.');
        }

        $this->cajas->registrarManual(
            $caja,
            $request->user(),
            TipoMovimientoCaja::from($datos['tipo']),
            (float) $datos['monto'],
            $datos['concepto'],
        );

        $etiqueta = $datos['tipo'] === TipoMovimientoCaja::INGRESO->value ? 'ingreso' : 'egreso';

        return redirect()->route('caja.index')
            ->with('success', ucfirst($etiqueta).' registrado correctamente.');
    }

    /**
     * Formulario de cierre con el resumen y el campo de efectivo contado.
     */
    public function cerrarFormulario(): Response|RedirectResponse
    {
        $caja = $this->cajas->actual();

        if ($caja === null) {
            return redirect()->route('caja.index')
                ->with('success', 'No hay una caja abierta para cerrar.');
        }

        return Inertia::render('Caja/Cerrar', [
            'caja_id' => $caja->id,
            'resumen' => $this->cajas->resumen($caja),
        ]);
    }

    /**
     * Cierra la caja abierta con el efectivo contado.
     */
    public function cerrar(CerrarCajaRequest $request): RedirectResponse
    {
        $caja = $this->cajas->actual();

        if ($caja === null) {
            return redirect()->route('caja.index')
                ->with('success', 'No hay una caja abierta para cerrar.');
        }

        $resultado = $this->cajas->cerrar(
            $caja,
            (float) $request->validated('efectivo_contado'),
        );

        $signo = $resultado['diferencia'] < 0 ? '-' : '';
        $diferencia = number_format(abs($resultado['diferencia']), 2, ',', '.');

        return redirect()->route('caja.index')
            ->with('success', "Caja cerrada correctamente. Diferencia: {$signo}\${$diferencia}.");
    }
}
