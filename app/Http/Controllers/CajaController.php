<?php

namespace App\Http\Controllers;

use App\Enums\MedioPago;
use App\Enums\TipoMovimientoCaja;
use App\Http\Requests\AbrirCajaRequest;
use App\Http\Requests\CerrarCajaRequest;
use App\Http\Requests\MovimientoCajaRequest;
use App\Models\Caja;
use App\Models\CajaFisica;
use App\Models\Usuario;
use App\Services\CajaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CajaController extends Controller
{
    public function __construct(private CajaService $cajas) {}

    /**
     * Estado de la caja del usuario: resumen de su sesión abierta, o pantalla
     * de "sin caja abierta" si no tiene una.
     */
    public function index(Request $request): Response
    {
        $caja = $this->cajaDelUsuario($request->user());

        return Inertia::render('Caja/Index', [
            'caja_abierta' => $caja !== null,
            'resumen' => $caja !== null ? $this->cajas->resumen($caja) : null,
        ]);
    }

    /**
     * Formulario para abrir una caja (solo si el usuario no tiene una abierta).
     * Lista las cajas físicas activas indicando cuáles están disponibles y
     * cuáles están actualmente en uso por otra sesión.
     */
    public function abrirFormulario(Request $request): Response
    {
        $ocupadas = Caja::query()->abierta()->pluck('caja_fisica_id');

        $cajasFisicas = CajaFisica::query()
            ->where('activa', true)
            ->orderBy('nombre')
            ->get()
            ->map(fn (CajaFisica $cf) => [
                'id' => $cf->id,
                'nombre' => $cf->nombre,
                'activa' => $cf->activa,
                'disponible' => ! $ocupadas->contains($cf->id),
            ])
            ->values();

        return Inertia::render('Caja/Abrir', [
            'hay_caja_abierta' => $this->cajaDelUsuario($request->user()) !== null,
            'cajas_fisicas' => $cajasFisicas,
        ]);
    }

    /**
     * Abre una caja con el monto inicial, asociada a una caja física.
     */
    public function abrir(AbrirCajaRequest $request): RedirectResponse
    {
        $cajaFisica = null;

        if ($request->validated('caja_fisica_id') !== null) {
            $cajaFisica = CajaFisica::find((int) $request->validated('caja_fisica_id'));
        }

        $this->cajas->abrir(
            $request->user(),
            (float) $request->validated('monto_inicial'),
            $cajaFisica,
        );

        return redirect()->route('caja.index')
            ->with('success', 'Caja abierta correctamente.');
    }

    /**
     * Registra un ingreso o egreso manual en la caja abierta del usuario.
     */
    public function movimiento(MovimientoCajaRequest $request): RedirectResponse
    {
        $datos = $request->validated();

        $caja = $this->cajaDelUsuario($request->user());

        if ($caja === null) {
            return redirect()->route('caja.index')
                ->with('success', 'No hay una caja abierta para registrar movimientos.');
        }

        $medioPago = isset($datos['medio_pago']) && $datos['medio_pago'] !== null
            ? MedioPago::from($datos['medio_pago'])
            : null;

        $this->cajas->registrarManual(
            $caja,
            $request->user(),
            TipoMovimientoCaja::from($datos['tipo']),
            (float) $datos['monto'],
            $datos['concepto'],
            $medioPago,
        );

        $etiqueta = $datos['tipo'] === TipoMovimientoCaja::INGRESO->value ? 'ingreso' : 'egreso';

        return redirect()->route('caja.index')
            ->with('success', ucfirst($etiqueta).' registrado correctamente.');
    }

    /**
     * Formulario de cierre con el resumen y el campo de efectivo contado.
     */
    public function cerrarFormulario(Request $request): Response|RedirectResponse
    {
        $caja = $this->cajaDelUsuario($request->user());

        if ($caja === null) {
            return redirect()->route('caja.index')
                ->with('success', 'No hay una caja abierta para cerrar.');
        }

        return Inertia::render('Caja/Cerrar', [
            'caja_id' => $caja->id,
            'caja_fisica_nombre' => $caja->cajaFisica?->nombre,
            'resumen' => $this->cajas->resumen($caja),
        ]);
    }

    /**
     * Cierra la caja abierta del usuario con el efectivo contado.
     */
    public function cerrar(CerrarCajaRequest $request): RedirectResponse
    {
        $caja = $this->cajaDelUsuario($request->user());

        if ($caja === null) {
            return redirect()->route('caja.index')
                ->with('success', 'No hay una caja abierta para cerrar.');
        }

        $resultado = $this->cajas->cerrar(
            $request->user(),
            $caja,
            (float) $request->validated('efectivo_contado'),
            $request->validated('observacion'),
        );

        $signo = $resultado['diferencia'] < 0 ? '-' : '';
        $diferencia = number_format(abs($resultado['diferencia']), 2, ',', '.');

        return redirect()->route('caja.index')
            ->with('success', "Caja cerrada correctamente. Diferencia: {$signo}\${$diferencia}.");
    }

    /**
     * Sesión de caja abierta de un usuario.
     */
    private function cajaDelUsuario(Usuario $usuario): ?Caja
    {
        return $this->cajas->actualDelUsuario($usuario);
    }
}
