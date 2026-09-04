<?php
/**
 * @var array $comercio
 * @var string $fecha
 * @var string $generado_por
 * @var list<string> $filtros
 * @var int $cantidad
 * @var \Illuminate\Support\Collection<int, App\Models\Producto> $productos
 */
$formatoMoneda = static fn ($monto): string => $monto === null || $monto === ''
    ? '—'
    : '$ '.number_format((float) $monto, 2, ',', '.');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1f2937; font-size: 11px; }
        .encabezado { border-bottom: 2px solid #15803d; padding-bottom: 14px; margin-bottom: 16px; }
        .encabezado .titulo { font-size: 20px; font-weight: bold; color: #15803d; margin: 0; }
        .encabezado .comercio { font-size: 13px; color: #4b5563; margin-top: 2px; }
        .encabezado .resumen {
            margin-top: 10px; font-size: 11px; color: #374151; line-height: 1.6;
        }
        .encabezado .resumen .etiqueta { font-weight: bold; color: #111827; }
        .filtros {
            margin-top: 12px; padding: 8px 10px; background: #f0fdf4;
            border: 1px solid #bbf7d0; border-radius: 4px; font-size: 10px; color: #166534;
        }
        .filtros .titulo-filtros { font-weight: bold; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; }
        thead th {
            background: #f3f4f6; color: #374151; text-align: left;
            padding: 8px 6px; border-bottom: 2px solid #d1d5db;
            font-size: 10px; text-transform: uppercase; letter-spacing: 0.03em;
        }
        tbody td { padding: 7px 6px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        tbody tr:nth-child(even) td { background: #fafafa; }
        td.columna-codigo, th.columna-codigo { width: 12%; }
        td.columna-nombre, th.columna-nombre { width: 24%; }
        td.columna-categoria, th.columna-categoria { width: 15%; }
        td.columna-tipo, th.columna-tipo { width: 15%; }
        td.columna-costo, th.columna-costo { width: 12%; }
        td.columna-venta, th.columna-venta { width: 12%; }
        td.columna-estado, th.columna-estado { width: 10%; }
        .monto, .estado { white-space: nowrap; }
        .negrita { font-weight: bold; }
        .pie { margin-top: 18px; font-size: 10px; color: #9ca3af; text-align: center; }
        @page { margin: 18mm 14mm; }
    </style>
</head>
<body>
    <div class="encabezado">
        <div class="titulo">Listado de productos</div>
        <div class="comercio">{{ $comercio['nombre'] ?? '' }}</div>
        <div class="resumen">
            <div><span class="etiqueta">Generado por:</span> {{ $generado_por }}</div>
            <div><span class="etiqueta">Fecha:</span> {{ $fecha }}</div>
            <div><span class="etiqueta">Productos listados:</span> {{ $cantidad }}</div>
        </div>
        @if (empty($filtros))
            <div class="filtros"><span class="titulo-filtros">Filtros:</span> Todos los productos</div>
        @else
            <div class="filtros">
                <div class="titulo-filtros">Filtros:</div>
                <div>{{ implode('  |  ', $filtros) }}</div>
            </div>
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th class="columna-codigo">Código</th>
                <th class="columna-nombre">Nombre</th>
                <th class="columna-categoria">Categoría</th>
                <th class="columna-tipo">Tipo de venta</th>
                <th class="columna-costo">P. costo</th>
                <th class="columna-venta">P. venta</th>
                <th class="columna-estado">Estado</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($productos as $producto)
                <tr>
                    <td class="columna-codigo">{{ $producto->codigo ?: '—' }}</td>
                    <td class="columna-nombre negrita">{{ $producto->nombre }}</td>
                    <td class="columna-categoria">{{ $producto->categoria?->nombre ?? '—' }}</td>
                    <td class="columna-tipo">{{ $producto->unidad_medida?->etiqueta() ?? '—' }}</td>
                    <td class="columna-costo monto">{{ $formatoMoneda($producto->costoVigente?->precio) }}</td>
                    <td class="columna-venta monto">{{ $formatoMoneda($producto->precioVigente?->monto) }}</td>
                    <td class="columna-estado estado">{{ $producto->activo ? 'Activo' : 'Inactivo' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" style="text-align:center; color:#6b7280; padding:24px;">
                        No hay productos que coincidan con los filtros.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="pie">Documento generado por el sistema de gestión de la verdulería.</div>
</body>
</html>
