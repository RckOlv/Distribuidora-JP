<?php

namespace Tests;

use App\Models\Producto;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Total (con el precio vigente del backend) que da un pedido.
     *
     * Se usa para completar el "efectivo_recibido" en las ventas en EFECTIVO,
     * que desde la Fase 9 debe cubrir el total de la venta.
     *
     * @param  list<array{producto_id: int, cantidad: float|string}>  $items
     */
    protected function totalDeItems(array $items): float
    {
        $total = 0.0;

        foreach ($items as $item) {
            $producto = Producto::with('precioVigente')->find($item['producto_id']);
            $total += (float) ($producto?->precioVigente?->monto ?? 0) * (float) $item['cantidad'];
        }

        return round($total, 2);
    }
}
