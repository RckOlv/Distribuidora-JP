<?php

namespace App\Http\Controllers;

use App\Enums\EstadoImpresion;
use App\Models\DispositivoImpresion;
use App\Models\TrabajoImpresion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API propia del print bridge.
 *
 * No usa la autenticación humana: valida el token Bearer de un dispositivo de
 * impresión (middleware dispositivo.impresion). Así el bridge solo puede leer/
 * confirmar trabajos de impresión, sin poder crear ventas ni administrar datos.
 */
class BridgeImpresionController extends Controller
{
    /**
     * Devuelve a lo sumo un trabajo para procesar, asignándolo (claim) al
     * dispositivo autenticado de forma atómica.
     *
     * Antes de tomar un pendiente, recupera trabajos PROCESANDO vencidos
     * (p. ej. tras un corte del bridge) y los devuelve a PENDIENTE.
     */
    public function pendientes(Request $request): JsonResponse
    {
        $dispositivo = $request->attributes->get('dispositivo');

        $this->recuperarTrabajosVencidos($dispositivo);

        $trabajo = $this->reclamarPendiente($dispositivo);

        if ($trabajo === null) {
            return response()->json(['trabajo' => null]);
        }

        return response()->json([
            'trabajo' => $this->serializar($trabajo),
        ]);
    }

    /**
     * El bridge informa impresión exitosa del trabajo que reclamó/está en PROCESANDO.
     */
    public function impreso(Request $request, TrabajoImpresion $trabajo): JsonResponse
    {
        $dispositivo = $request->attributes->get('dispositivo');

        if ($this->perteneceAlDispositivo($trabajo, $dispositivo) === false) {
            return response()->json(['message' => 'El trabajo no pertenece a este dispositivo.'], 403);
        }

        if ($trabajo->estado !== EstadoImpresion::PROCESANDO) {
            return response()->json(['message' => 'El trabajo no está en PROCESANDO.'], 409);
        }

        $trabajo->update([
            'estado' => EstadoImpresion::IMPRESO->value,
            'impreso_en' => now(),
            'procesando_at' => null,
        ]);

        return response()->json($this->serializar($trabajo->refresh()));
    }

    /**
     * El bridge informa un fallo. Los errores temporales de impresora/red
     * devuelven el trabajo a PENDIENTE para poder reintentarlo de forma
     * controlada; solo quedan en ERROR errores que no tengan sentido reintentar.
     */
    public function error(Request $request, TrabajoImpresion $trabajo): JsonResponse
    {
        $dispositivo = $request->attributes->get('dispositivo');

        if ($this->perteneceAlDispositivo($trabajo, $dispositivo) === false) {
            return response()->json(['message' => 'El trabajo no pertenece a este dispositivo.'], 403);
        }

        $datos = $request->validate([
            'error' => ['required', 'string', 'max:2000'],
            'reintentar' => ['sometimes', 'boolean'],
        ]);

        $reintentar = $datos['reintentar'] ?? true;

        if ($reintentar) {
            // Vuelve a estar disponible: el bridge (u otro) podrá reintentarlo.
            $trabajo->update([
                'estado' => EstadoImpresion::PENDIENTE->value,
                'dispositivo_id' => null,
                'procesando_at' => null,
                'ultimo_error' => $datos['error'],
            ]);
        } else {
            $trabajo->update([
                'estado' => EstadoImpresion::ERROR->value,
                'procesando_at' => null,
                'ultimo_error' => $datos['error'],
            ]);
        }

        return response()->json($this->serializar($trabajo->refresh()));
    }

    /**
     * Claim atómico: PENDIENTE → PROCESANDO asignado a este dispositivo.
     *
     * La actualización condicional (WHERE estado=PENDIENTE) evita que dos
     * bridges tomen el mismo trabajo: solo uno afecta filas (1). Máximo 1.
     */
    private function reclamarPendiente(DispositivoImpresion $dispositivo): ?TrabajoImpresion
    {
        $candidato = TrabajoImpresion::query()
            ->where('estado', EstadoImpresion::PENDIENTE->value)
            ->orderBy('id')
            ->first();

        if ($candidato === null) {
            return null;
        }

        $afectadas = TrabajoImpresion::query()
            ->whereKey($candidato->id)
            ->where('estado', EstadoImpresion::PENDIENTE->value)
            ->update([
                'estado' => EstadoImpresion::PROCESANDO->value,
                'dispositivo_id' => $dispositivo->id,
                'procesando_at' => now(),
                'cantidad_intentos' => $candidato->cantidad_intentos + 1,
            ]);

        if ($afectadas === 0) {
            return null;
        }

        return $candidato->refresh()->load('ticket');
    }

    /**
     * Trabajos PROCESANDO que llevan más del límite configurado sin ser
     * confirmados: se asumen huérfanos (el bridge murió) y vuelven a PENDIENTE.
     */
    private function recuperarTrabajosVencidos(DispositivoImpresion $dispositivo): void
    {
        $limiteMinutos = (int) config('impresion.recuperar_procesando_minutos', 5);

        TrabajoImpresion::query()
            ->where('estado', EstadoImpresion::PROCESANDO->value)
            ->where('procesando_at', '<', now()->subMinutes($limiteMinutos))
            ->update([
                'estado' => EstadoImpresion::PENDIENTE->value,
                'dispositivo_id' => null,
                'procesando_at' => null,
            ]);
    }

    private function perteneceAlDispositivo(
        TrabajoImpresion $trabajo,
        DispositivoImpresion $dispositivo,
    ): bool {
        return $trabajo->dispositivo_id === $dispositivo->id
            && $trabajo->estado === EstadoImpresion::PROCESANDO;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializar(TrabajoImpresion $trabajo): array
    {
        return [
            'id' => $trabajo->id,
            'ticket_id' => $trabajo->ticket_id,
            'tipo' => $trabajo->tipo->value,
            'numero_ticket' => $trabajo->ticket?->numero,
            'estado' => $trabajo->estado->value,
            'cantidad_intentos' => $trabajo->cantidad_intentos,
            'ultimo_error' => $trabajo->ultimo_error,
            'impreso_en' => $trabajo->impreso_en?->toIso8601String(),
            'contenido' => $trabajo->ticket?->contenido,
        ];
    }
}
