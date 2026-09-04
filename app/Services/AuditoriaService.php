<?php

namespace App\Services;

use App\Enums\AccionAuditoria;
use App\Models\Auditoria;
use App\Models\Usuario;

/**
 * Centraliza la creación de registros de auditoría.
 *
 * Todas las operaciones relevantes del sistema pasan por acá en lugar de
 * duplicar Auditoria::create(...) en cada controlador/servicio.
 */
class AuditoriaService
{
    /**
     * Registra una acción de auditoría.
     *
     * @param  AccionAuditoria  $accion  acción realizada
     * @param  ?Usuario  $usuario  usuario que la realizó (nullable)
     * @param  string  $entidadTipo  tipo de entidad (p. ej. 'producto')
     * @param  ?int  $entidadId  id del registro afectado
     * @param  ?string  $descripcion  descripción legible de la acción
     * @param  array<string, mixed>|null  $datosAnteriores  valores previos relevantes
     * @param  array<string, mixed>|null  $datosNuevos  valores posteriores relevantes
     */
    public function registrar(
        AccionAuditoria $accion,
        ?Usuario $usuario,
        string $entidadTipo,
        ?int $entidadId = null,
        ?string $descripcion = null,
        ?array $datosAnteriores = null,
        ?array $datosNuevos = null,
    ): Auditoria {
        return Auditoria::create([
            'usuario_id' => $usuario?->id,
            'accion' => $accion->value,
            'entidad_tipo' => $entidadTipo,
            'entidad_id' => $entidadId,
            'descripcion' => $descripcion,
            'datos_anteriores' => $datosAnteriores,
            'datos_nuevos' => $datosNuevos,
        ]);
    }
}
