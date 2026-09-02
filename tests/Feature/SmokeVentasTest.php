<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Categoria;
use App\Models\Permiso;
use App\Models\Precio;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Usuario;
use App\Models\Venta;
use App\Support\Permisos as PermisosDisponibles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SmokeVentasTest extends TestCase
{
    use RefreshDatabase;

    public function test_smoke_fase8_flujo_completo(): void
    {
        // Usuarios
        $cajero = $this->usuario(Rol::CAJERO, [PermisosDisponibles::VENTAS_VER, PermisosDisponibles::POS_USAR, PermisosDisponibles::VENTAS_REALIZAR, PermisosDisponibles::CAJAS_USAR]);
        $producto = $this->producto('Banana', 'KILOGRAMO', 2500);

        // 1. abrir caja
        $this->actingAs($cajero)->post('/caja/abrir', ['monto_inicial' => 10000])->assertRedirect();
        $caja = Caja::first();
        $this->assertSame('ABIERTA', $caja->estado->value);

        // 2. vender por HTTP (2 kg → 5000)
        $this->actingAs($cajero)->post('/pos/ventas', [
            'medio_pago' => 'EFECTIVO',
            'items' => [['producto_id' => $producto->id, 'cantidad' => 2]],
        ])->assertRedirect();

        // 3. aparece en /ventas
        $this->actingAs($cajero)->get('/ventas')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Ventas/Index')
                ->has('ventas.data', 1)
                ->where('ventas.data.0.total', 5000)
                ->where('ventas.data.0.caja_id', $caja->id));

        $ventaId = Venta::first()->id;

        // 4. detalle
        $this->actingAs($cajero)->get('/ventas/'.$ventaId)->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Ventas/Show')
                ->where('venta.total', 5000)
                ->where('detalles.0.precio_unitario', 2500)
                ->where('detalles.0.cantidad', 2)
                ->where('detalles.0.subtotal', 5000));

        // 5. cerrar caja
        $this->actingAs($cajero)->post('/caja/cerrar', ['efectivo_contado' => 15000])->assertRedirect();
        $this->assertSame('CERRADA', $caja->refresh()->estado->value);

        // 6. sigue apareciendo con caja cerrada
        $this->actingAs($cajero)->get('/ventas')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->has('ventas.data', 1));
        $this->actingAs($cajero)->get('/ventas/'.$ventaId)->assertOk();

        // 7-8. cambiar precio del producto → el detalle histórico conserva el original
        $this->cambiarPrecio($producto, 99999);
        $this->actingAs($cajero)->get('/ventas/'.$ventaId)->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('detalles.0.precio_unitario', 2500)
                ->where('detalles.0.subtotal', 5000)
                ->where('venta.total', 5000));
    }

    private function producto(string $nombre, string $unidad, float $monto): Producto
    {
        $cat = Categoria::firstOrCreate(['nombre' => 'Frutas'], ['activa' => true]);
        $p = Producto::create(['categoria_id' => $cat->id, 'nombre' => $nombre, 'unidad_medida' => $unidad, 'activo' => true]);
        Precio::create(['producto_id' => $p->id, 'monto' => $monto, 'vigente' => true, 'usuario_id' => null]);

        return $p;
    }

    private function cambiarPrecio(Producto $p, float $monto): void
    {
        Precio::where('producto_id', $p->id)->where('vigente', true)->update(['vigente' => false]);
        Precio::create(['producto_id' => $p->id, 'monto' => $monto, 'vigente' => true, 'usuario_id' => null]);
    }

    private function usuario(string $rolNombre, array $permisos): Usuario
    {
        $rol = Rol::firstOrCreate(['nombre' => $rolNombre]);
        $rol->permisos()->sync(collect($permisos)->unique()
            ->map(fn (string $n) => Permiso::firstOrCreate(['nombre' => $n]))->pluck('id'));

        return Usuario::factory()->create(['rol_id' => $rol->id]);
    }
}
