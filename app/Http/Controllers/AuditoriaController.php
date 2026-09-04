<?php

namespace App\Http\Controllers;

use App\Enums\AccionAuditoria;
use App\Http\Requests\AuditoriaHistorialRequest;
use App\Models\Auditoria;
use App\Models\Usuario;
use Inertia\Inertia;
use Inertia\Response;

class AuditoriaController extends Controller
{
    /**
     * Historial de auditoría: listado paginado con filtros server-side.
     *
     * Solo lectura. La inserción de registros la realiza AuditoriaService
     * dentro de las transacciones de cada operación.
     */
    public function index(AuditoriaHistorialRequest $request): Response
    {
        $datos = $request->validated();
        $usuarioId = ($datos['usuario_id'] ?? null) ?: null;
        $accion = $datos['accion'] ?? '';
        $entidadTipo = trim((string) ($datos['entidad_tipo'] ?? ''));

        $registros = Auditoria::query()
            ->select([
                'id',
                'usuario_id',
                'accion',
                'entidad_tipo',
                'entidad_id',
                'descripcion',
                'created_at',
            ])
            ->with('usuario:id,name')
            ->when($this->fecha($datos['fecha_desde'] ?? null), function ($query, $fecha) {
                $query->whereDate('auditoria.created_at', '>=', $fecha);
            })
            ->when($this->fecha($datos['fecha_hasta'] ?? null), function ($query, $fecha) {
                $query->whereDate('auditoria.created_at', '<=', $fecha);
            })
            ->when($usuarioId !== null, function ($query) use ($usuarioId) {
                $query->where('auditoria.usuario_id', $usuarioId);
            })
            ->when($accion !== '', function ($query) use ($accion) {
                $query->where('auditoria.accion', $accion);
            })
            ->when($entidadTipo !== '', function ($query) use ($entidadTipo) {
                $query->where('auditoria.entidad_tipo', $entidadTipo);
            })
            ->orderByDesc('auditoria.created_at')
            ->orderByDesc('auditoria.id')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Auditoria $registro) => $this->formatoLista($registro));

        $filtros = [
            'fecha_desde' => $datos['fecha_desde'] ?? null,
            'fecha_hasta' => $datos['fecha_hasta'] ?? null,
            'usuario_id' => $usuarioId,
            'accion' => $accion,
            'entidad_tipo' => $entidadTipo,
        ];

        return Inertia::render('Auditoria/Index', [
            'registros' => $registros,
            'usuarios' => Usuario::query()->orderBy('name')->get(['id', 'name']),
            'acciones' => collect(AccionAuditoria::cases())
                ->map(fn (AccionAuditoria $accion) => [
                    'valor' => $accion->value,
                    'etiqueta' => $accion->etiqueta(),
                ])
                ->values(),
            'entidades' => $this->entidades(),
            'filtros' => $filtros,
        ]);
    }

    /**
     * Detalle de un registro de auditoría con sus datos anteriores/nuevos.
     */
    public function show(int $auditoria): Response
    {
        $registro = Auditoria::query()
            ->with('usuario:id,name')
            ->findOrFail($auditoria);

        return Inertia::render('Auditoria/Show', [
            'registro' => [
                'id' => $registro->id,
                'fecha' => $registro->created_at?->toIso8601String(),
                'usuario' => $registro->usuario?->name,
                'accion' => $registro->accion,
                'accion_etiqueta' => AccionAuditoria::from($registro->accion)->etiqueta(),
                'entidad_tipo' => $registro->entidad_tipo,
                'entidad_id' => $registro->entidad_id,
                'descripcion' => $registro->descripcion,
                'datos_anteriores' => $registro->datos_anteriores,
                'datos_nuevos' => $registro->datos_nuevos,
            ],
        ]);
    }

    /**
     * Formatea un registro para la tabla del historial.
     *
     * @return array<string, mixed>
     */
    private function formatoLista(Auditoria $registro): array
    {
        return [
            'id' => $registro->id,
            'fecha' => $registro->created_at?->toIso8601String(),
            'usuario' => $registro->usuario?->name,
            'accion' => $registro->accion,
            'accion_etiqueta' => AccionAuditoria::from($registro->accion)->etiqueta(),
            'entidad_tipo' => $registro->entidad_tipo,
            'entidad_id' => $registro->entidad_id,
            'descripcion' => $registro->descripcion,
        ];
    }

    /**
     * Fecha de filtro normalizada (nullable si vacía).
     */
    private function fecha(?string $fecha): ?string
    {
        if ($fecha === null || $fecha === '') {
            return null;
        }

        return (string) $fecha;
    }

    /**
     * Tipos de entidad auditable para el filtro.
     *
     * @return list<array{valor: string, etiqueta: string}>
     */
    private function entidades(): array
    {
        return [
            ['valor' => 'usuario', 'etiqueta' => 'Usuario'],
            ['valor' => 'producto', 'etiqueta' => 'Producto'],
            ['valor' => 'categoria', 'etiqueta' => 'Categoría'],
            ['valor' => 'precio', 'etiqueta' => 'Precio'],
            ['valor' => 'costo', 'etiqueta' => 'Costo'],
            ['valor' => 'caja', 'etiqueta' => 'Caja'],
            ['valor' => 'movimiento_caja', 'etiqueta' => 'Movimiento de caja'],
            ['valor' => 'venta', 'etiqueta' => 'Venta'],
        ];
    }
}
