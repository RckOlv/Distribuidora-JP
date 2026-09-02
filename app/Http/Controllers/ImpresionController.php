<?php

namespace App\Http\Controllers;

use App\Enums\EstadoImpresion;
use App\Models\TrabajoImpresion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cola de impresión preparada para el futuro print bridge.
 *
 * Los endpoints están protegidos por sesión + permiso «impresiones.gestionar»
 * como medida de seguridad inicial. Cuando exista el bridge (Fase 5B), se
 * incorporará la autenticación específica de dispositivos sin rehacer este
 * dominio: el bridge podrá tomar trabajos con credenciales dedicadas.
 */
class ImpresionController extends Controller
{
    /**
     * Todos los trabajos pendientes que un bridge puede procesar.
     */
    public function pendientes(): JsonResponse
    {
        $trabajos = TrabajoImpresion::query()
            ->with('ticket:id,numero,venta_id')
            ->where('estado', EstadoImpresion::PENDIENTE->value)
            ->orderBy('id')
            ->get()
            ->map(fn (TrabajoImpresion $trabajo) => $this->serializar($trabajo));

        return response()->json($trabajos);
    }

    /**
     * Toma un trabajo: PENDIENTE → PROCESANDO.
     *
     * La actualización condicional evita que dos consumidores procesen el
     * mismo trabajo a la vez: si otro bridge ya lo tomó, no hay filas afectadas.
     */
    public function procesando(TrabajoImpresion $trabajo): JsonResponse
    {
        $tomado = TrabajoImpresion::query()
            ->whereKey($trabajo->id)
            ->where('estado', EstadoImpresion::PENDIENTE->value)
            ->update([
                'estado' => EstadoImpresion::PROCESANDO->value,
                'cantidad_intentos' => $trabajo->cantidad_intentos + 1,
            ]);

        if ($tomado === 0) {
            return response()->json([
                'message' => 'El trabajo ya fue tomado por otro procesador o no está pendiente.',
            ], 409);
        }

        return response()->json($this->serializar($trabajo->refresh()));
    }

    /**
     * Confirma que el trabajo se imprimió: → IMPRESO con su fecha.
     */
    public function impreso(TrabajoImpresion $trabajo): JsonResponse
    {
        $trabajo->update([
            'estado' => EstadoImpresion::IMPRESO->value,
            'impreso_en' => now(),
        ]);

        return response()->json($this->serializar($trabajo->refresh()));
    }

    /**
     * Informa un fallo de impresión: → ERROR con el mensaje correspondiente.
     */
    public function error(Request $request, TrabajoImpresion $trabajo): JsonResponse
    {
        $datos = $request->validate([
            'error' => ['nullable', 'string', 'max:2000'],
        ]);

        $trabajo->update([
            'estado' => EstadoImpresion::ERROR->value,
            'ultimo_error' => $datos['error'] ?? 'Error de impresión',
        ]);

        return response()->json($this->serializar($trabajo->refresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializar(TrabajoImpresion $trabajo): array
    {
        return [
            'id' => $trabajo->id,
            'ticket_id' => $trabajo->ticket_id,
            'numero_ticket' => $trabajo->ticket?->numero,
            'estado' => $trabajo->estado->value,
            'cantidad_intentos' => $trabajo->cantidad_intentos,
            'ultimo_error' => $trabajo->ultimo_error,
            'impreso_en' => $trabajo->impreso_en?->toIso8601String(),
            'created_at' => $trabajo->created_at?->toIso8601String(),
        ];
    }
}
