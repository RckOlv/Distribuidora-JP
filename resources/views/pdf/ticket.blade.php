@php
    /**
     * Ticket térmico de 80 mm.
     * @var array{
     *   numero?: string,
     *   fecha?: string,
     *   usuario?: string,
     *   medio_pago?: string,
     *   caja_fisica?: string|null,
     *   total?: string,
     *   efectivo_recibido?: string|null,
     *   vuelto?: string|null,
     *   comercio?: array{nombre?: string, direccion?: string, telefono?: string, leyenda?: string},
     *   detalles?: array<int, array{nombre?: string, unidad_medida?: string, cantidad?: string, precio_unitario?: string, subtotal?: string}>
     * } $ticket
     */
    $comercio = $ticket['comercio'] ?? [];
    $detalles = $ticket['detalles'] ?? [];

    $moneda = static fn ($valor): string => '$ '.number_format((float) $valor, 2, ',', '.');

    $etiquetaUnidad = static function (string $unidad) use (&$etiquetaUnidad): string {
        $corta = [
            'KILOGRAMO' => 'kg',
            'BOLSA' => 'bolsa',
            'UNIDAD' => 'unidad',
            'GRAMO' => 'g',
            'LITRO' => 'L',
        ];

        return $corta[$unidad] ?? strtolower($unidad);
    };

    // Cantidad con coma decimal y hasta 3 decimales (p. ej. 1,250 kg).
    $cantidad = static function ($valor) use (&$cantidad): string {
        $n = (float) $valor;
        return number_format($n, 3, ',', '.');
    };
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 3mm; }
        * { font-family: DejaVu Sans, sans-serif; box-sizing: border-box; }
        body {
            color: #111827;
            font-size: 11px;
            line-height: 1.35;
            margin: 0;
            width: 100%;
        }
        .centro { text-align: center; }
        .comercio { font-size: 14px; font-weight: bold; }
        .subtitulo { font-size: 10px; color: #374151; }
        .meta { margin-top: 6px; font-size: 10px; color: #374151; }
        .meta .fila { text-align: center; }
        .separador { border-top: 1px dashed #9ca3af; margin: 6px 0; }
        .items { width: 100%; border-collapse: collapse; }
        .items td { vertical-align: top; padding: 1px 0; font-size: 11px; }
        .items td .nombre { font-weight: bold; }
        .items td .detalle { font-size: 10px; color: #4b5563; }
        .precio { text-align: right; white-space: nowrap; }
        .total-de { border-top: 1px solid #111827; padding: 2px 0 0; }
        .total-de td { font-weight: bold; font-size: 12px; }
        .pago { margin-top: 6px; font-weight: bold; }
        .leyenda { margin-top: 8px; text-align: center; font-size: 10px; color: #374151; }
        .separador-bottom { border-top: 1px solid #9ca3af; margin-top: 6px; }
    </style>
</head>
<body>
    <div class="centro">
        <div class="comercio">{{ $comercio['nombre'] ?? '' }}</div>
        @if (! empty($comercio['direccion']))
            <div class="subtitulo">{{ $comercio['direccion'] }}</div>
        @endif
        @if (! empty($comercio['telefono']))
            <div class="subtitulo">{{ $comercio['telefono'] }}</div>
        @endif
    </div>

    <div class="meta">
        <div class="fila"><strong>Ticket #{{ $ticket['numero'] ?? '' }}</strong></div>
        <div class="fila">{{ $ticket['fecha'] ?? '' }}</div>
        @if (! empty($ticket['caja_fisica']))
            <div class="fila">Caja {{ $ticket['caja_fisica'] }}</div>
        @endif
        <div class="fila">{{ $ticket['usuario'] ?? '' }}</div>
    </div>

    <div class="separador"></div>

    <table class="items">
        @forelse ($detalles as $detalle)
            <tr>
                <td>
                    <div class="nombre">{{ $detalle['nombre'] ?? '' }}</div>
                    <div class="detalle">
                        {{ $cantidad($detalle['cantidad'] ?? 0) }}
                        {{ $etiquetaUnidad($detalle['unidad_medida'] ?? 'UNIDAD') }}
                    </div>
                </td>
                <td class="precio">
                    {{ $moneda($detalle['precio_unitario'] ?? 0) }}<br>
                    <span style="color:#4b5563;">{{ $moneda($detalle['subtotal'] ?? 0) }}</span>
                </td>
            </tr>
        @empty
            <tr>
                <td class="centro" style="padding:6px 0;">Sin detalle de productos.</td>
            </tr>
        @endforelse
    </table>

    <div class="separador"></div>

    <table class="items total-de">
        <tr>
            <td>TOTAL</td>
            <td class="precio">{{ $moneda($ticket['total'] ?? 0) }}</td>
        </tr>
    </table>

    @if (! empty($ticket['efectivo_recibido']))
        <table class="items">
            <tr>
                <td>Recibido</td>
                <td class="precio">{{ $moneda($ticket['efectivo_recibido']) }}</td>
            </tr>
        </table>
    @endif

    @if (! empty($ticket['vuelto']))
        <table class="items">
            <tr>
                <td class="pago">Vuelto</td>
                <td class="precio pago">{{ $moneda($ticket['vuelto']) }}</td>
            </tr>
        </table>
    @endif

    @if (! empty($ticket['pagos']) && is_array($ticket['pagos']))
        <table class="items">
            @foreach ($ticket['pagos'] as $pago)
                <tr>
                    <td>Pago: {{ $pago['etiqueta'] ?? $pago['medio_pago'] ?? '' }}</td>
                    <td class="precio">{{ $moneda($pago['monto'] ?? 0) }}</td>
                </tr>
            @endforeach
        </table>
    @elseif (! empty($ticket['medio_pago']))
        <div class="pago">Pago: {{ $ticket['medio_pago'] }}</div>
    @endif

    @if (! empty($comercio['leyenda']))
        <div class="leyenda">{{ $comercio['leyenda'] }}</div>
    @endif

    <div class="separador-bottom"></div>
</body>
</html>
