<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReporteRequest;
use App\Services\ReporteService;
use Inertia\Inertia;
use Inertia\Response;

class ReporteController extends Controller
{
    /**
     * Resumen de ventas, ganancias y caja para un rango de fechas.
     *
     * Solo lectura. El rango se envía como desde/hasta (YYYY-MM-DD); si no se
     * envía, se usa el día de hoy (timezone de Laravel). Se mantiene 'fecha'
     * como alias heredado para una consulta puntual de un solo día.
     */
    public function index(ReporteRequest $request, ReporteService $servicio): Response
    {
        $hoy = now(config('app.timezone'))->format('Y-m-d');

        $desde = (string) ($request->validated('desde')
            ?? $request->validated('fecha')
            ?? $hoy);
        $hasta = (string) ($request->validated('hasta')
            ?? $request->validated('fecha')
            ?? $hoy);

        return Inertia::render('Reportes/Index', [
            'resumen' => $servicio->resumen($desde, $hasta),
        ]);
    }
}
