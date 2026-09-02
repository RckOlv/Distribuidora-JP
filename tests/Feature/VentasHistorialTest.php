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
use App\Services\CajaService;
use App\Services\VentaService;
use App\Support\Permisos as PermisosDisponibles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class VentasHistorialTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_con_ventas_ver_puede_acceder_al_historial(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->get('/ventas')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Ventas/Index'));
    }

    public function test_usuario_sin_ventas_ver_recibe_403(): void
    {
        $usuario = $this->crearUsuarioConRol(Rol::CAJERO, [PermisosDisponibles::POS_USAR]);
        $ventaid = $this->crearVenta(
            $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::VENTAS_VER]),
            [['producto_id' => $this->crearVendible('Papa', 'KILOGRAMO', 1000)->id, 'cantidad' => 1]],
        )->id;

        $this->actingAs($usuario)->get('/ventas')->assertForbidden();
        $this->actingAs($usuario)->get('/ventas/'.$ventaid)->assertForbidden();
    }

    public function test_el_listado_devuelve_las_ventas(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, [PermisosDisponibles::VENTAS_VER]);
        $venta = $this->crearVenta($cajero, [['producto_id' => $this->crearVendible('Papa', 'KILOGRAMO', 1000)->id, 'cantidad' => 2]]);

        $this->actingAs($cajero)
            ->get('/ventas')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Ventas/Index')
                ->has('ventas.data', 1)
                ->where('ventas.data.0.id', $venta->id)
                ->where('ventas.data.0.total', 2000));
    }

    public function test_las_ventas_aparecen_ordenadas_de_mas_reciente_a_mas_antigua(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, [PermisosDisponibles::VENTAS_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);

        $v1 = $this->crearVenta($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);
        $v2 = $this->crearVenta($cajero, [['producto_id' => $producto->id, 'cantidad' => 2]]);

        // Forzamos fechas distintas para ordenar con claridad.
        $v1->forceFill(['created_at' => Carbon::parse('2026-01-01 10:00:00')])->save();
        $v2->forceFill(['created_at' => Carbon::parse('2026-03-01 10:00:00')])->save();

        $this->actingAs($cajero)
            ->get('/ventas')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('ventas.data.0.id', $v2->id)
                ->where('ventas.data.1.id', $v1->id));
    }

    public function test_filtro_por_fecha_desde(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, [PermisosDisponibles::VENTAS_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);

        $antes = $this->crearVenta($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);
        $despues = $this->crearVenta($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        $antes->forceFill(['created_at' => Carbon::parse('2026-01-01 10:00:00')])->save();
        $despues->forceFill(['created_at' => Carbon::parse('2026-03-01 10:00:00')])->save();

        // Desde el 1 de marzo: sólo debe quedar la venta de marzo.
        $this->actingAs($cajero)
            ->get('/ventas?fecha_desde=2026-03-01')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('ventas.data', 1)
                ->where('ventas.data.0.id', $despues->id));
    }

    public function test_filtro_por_fecha_hasta(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, [PermisosDisponibles::VENTAS_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);

        $antes = $this->crearVenta($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);
        $despues = $this->crearVenta($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        $antes->forceFill(['created_at' => Carbon::parse('2026-01-01 10:00:00')])->save();
        $despues->forceFill(['created_at' => Carbon::parse('2026-03-01 10:00:00')])->save();

        // Hasta el 31 de enero: sólo debe quedar la venta de enero.
        $this->actingAs($cajero)
            ->get('/ventas?fecha_hasta=2026-01-31')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('ventas.data', 1)
                ->where('ventas.data.0.id', $antes->id));
    }

    public function test_se_validan_las_fechas(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, [PermisosDisponibles::VENTAS_VER]);

        $this->actingAs($cajero)
            ->get('/ventas?fecha_desde=no-es-una-fecha')
            ->assertSessionHasErrors('fecha_desde');

        $this->actingAs($cajero)
            ->get('/ventas?fecha_desde=2026-09-02&fecha_hasta=2026-09-01')
            ->assertSessionHasErrors('fecha_hasta');
    }

    public function test_filtro_por_numero_de_venta(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, [PermisosDisponibles::VENTAS_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);

        $v1 = $this->crearVenta($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);
        $v2 = $this->crearVenta($cajero, [['producto_id' => $producto->id, 'cantidad' => 2]]);

        $this->actingAs($cajero)
            ->get('/ventas?numero='.$v2->id)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('ventas.data', 1)
                ->where('ventas.data.0.id', $v2->id));

        // Acepta el número con ceros a la izquierda (#000002).
        $this->actingAs($cajero)
            ->get('/ventas?numero='.str_pad((string) $v2->id, 6, '0', STR_PAD_LEFT))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('ventas.data', 1)
                ->where('ventas.data.0.id', $v2->id));
    }

    public function test_filtro_por_usuario(): void
    {
        $cajeroA = $this->crearUsuarioConRol(Rol::CAJERO, [PermisosDisponibles::VENTAS_VER]);
        $cajeroB = $this->crearUsuarioConRol(Rol::CAJERO, [PermisosDisponibles::VENTAS_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);

        $this->crearVenta($cajeroA, [['producto_id' => $producto->id, 'cantidad' => 1]]);
        $this->crearVenta($cajeroB, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        $this->actingAs($cajeroA)
            ->get('/ventas?usuario_id='.$cajeroB->id)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('ventas.data', 1)
                ->where('ventas.data.0.id', Venta::where('usuario_id', $cajeroB->id)->first()->id));
    }

    public function test_filtro_por_medio_de_pago(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, [PermisosDisponibles::VENTAS_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);

        $efectivo = $this->crearVenta($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]], 'EFECTIVO');
        $this->crearVenta($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]], 'TRANSFERENCIA');

        $this->actingAs($cajero)
            ->get('/ventas?medio_pago=EFECTIVO')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('ventas.data', 1)
                ->where('ventas.data.0.id', $efectivo->id));
    }

    public function test_los_medios_de_pago_solo_aceptan_valores_validos(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, [PermisosDisponibles::VENTAS_VER]);

        $this->actingAs($cajero)
            ->get('/ventas?medio_pago=CREDITO')
            ->assertSessionHasErrors('medio_pago');
    }

    public function test_funciona_la_paginacion(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, [PermisosDisponibles::VENTAS_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);

        for ($i = 0; $i < 12; $i++) {
            $this->crearVenta($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);
        }

        $this->actingAs($cajero)
            ->get('/ventas')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('ventas.data', 10)
                ->where('ventas.to', 10)
                ->where('ventas.total', 12));

        $this->actingAs($cajero)
            ->get('/ventas?page=2')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('ventas.data', 2));
    }

    public function test_los_filtros_se_conservan_al_cambiar_de_pagina(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, [PermisosDisponibles::VENTAS_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);

        for ($i = 0; $i < 12; $i++) {
            $this->crearVenta($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]], 'EFECTIVO');
        }
        // Una venta por transferencia no debe aparecer con el filtro efectivo.
        $this->crearVenta($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]], 'TRANSFERENCIA');

        $this->actingAs($cajero)
            ->get('/ventas?medio_pago=EFECTIVO')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('ventas.next_page_url', fn ($url) => str_contains($url, 'medio_pago=EFECTIVO'))
                ->where('ventas.total', 12));
    }

    public function test_se_puede_consultar_el_detalle_de_una_venta(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, [PermisosDisponibles::VENTAS_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);
        $venta = $this->crearVenta($cajero, [['producto_id' => $producto->id, 'cantidad' => 3]]);

        $this->actingAs($cajero)
            ->get('/ventas/'.$venta->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Ventas/Show')
                ->where('venta.id', $venta->id)
                ->has('detalles', 1));
    }

    public function test_una_venta_inexistente_devuelve_404(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, [PermisosDisponibles::VENTAS_VER]);

        $this->actingAs($cajero)->get('/ventas/99999')->assertNotFound();
    }

    public function test_el_detalle_conserva_el_precio_del_momento_de_la_venta(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, [PermisosDisponibles::VENTAS_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);
        $venta = $this->crearVenta($cajero, [['producto_id' => $producto->id, 'cantidad' => 2]]);

        $this->actingAs($cajero)
            ->get('/ventas/'.$venta->id)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('detalles.0.precio_unitario', 1000)
                ->where('detalles.0.subtotal', 2000)
                ->where('venta.total', 2000));
    }

    public function test_cambiar_el_precio_despues_no_modifica_el_detalle_historico(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, [PermisosDisponibles::VENTAS_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);
        $venta = $this->crearVenta($cajero, [['producto_id' => $producto->id, 'cantidad' => 2]]);

        $this->cambiarPrecio($producto, 5000);

        $this->actingAs($cajero)
            ->get('/ventas/'.$venta->id)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('detalles.0.precio_unitario', 1000)
                ->where('detalles.0.subtotal', 2000)
                ->where('venta.total', 2000));
    }

    public function test_una_venta_de_una_caja_cerrada_sigue_apareciendo(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, [
            PermisosDisponibles::VENTAS_VER,
            PermisosDisponibles::CAJAS_USAR,
        ]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);
        $caja = $this->abrirCaja($cajero, 1000);
        $venta = $this->crearVentaEnCaja($cajero, $caja, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        $this->cerrarCaja($cajero, $caja, 2000);

        $this->actingAs($cajero)
            ->get('/ventas')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('ventas.data', 1));

        $this->actingAs($cajero)
            ->get('/ventas/'.$venta->id)
            ->assertOk();
    }

    public function test_el_detalle_muestra_usuario_caja_y_productos(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, [PermisosDisponibles::VENTAS_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);
        $venta = $this->crearVenta($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        $this->actingAs($cajero)
            ->get('/ventas/'.$venta->id)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('venta.usuario', $cajero->name)
                ->where('venta.caja_id', $venta->caja_id)
                ->where('detalles.0.nombre', 'Papa')
                ->where('detalles.0.cantidad', 1));
    }

    public function test_no_se_puede_modificar_una_venta_desde_estas_rutas(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, [PermisosDisponibles::VENTAS_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);
        $venta = $this->crearVenta($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        // Solo existe la ruta GET: los demás verbos no están permitidos.
        $this->actingAs($cajero)
            ->post('/ventas/'.$venta->id, ['total' => 0])
            ->assertMethodNotAllowed();

        $this->actingAs($cajero)
            ->put('/ventas/'.$venta->id, ['total' => 0])
            ->assertMethodNotAllowed();

        $this->actingAs($cajero)
            ->delete('/ventas/'.$venta->id)
            ->assertMethodNotAllowed();

        $this->assertDatabaseHas('ventas', ['id' => $venta->id, 'total' => 1000]);
    }

    // ------------------------------------------------------------------ Helpers

    private function abrirCaja(Usuario $cajero, float $monto): Caja
    {
        return app(CajaService::class)->abrir($cajero, $monto);
    }

    private function cerrarCaja(Usuario $cajero, Caja $caja, float $contado): void
    {
        app(CajaService::class)->cerrar($caja, $contado);
    }

    private function abrirCajaAbiertaPara(Usuario $cajero): Caja
    {
        return app(CajaService::class)->actual() ?? $this->abrirCaja($cajero, 0);
    }

    private function crearVentaEnCaja(Usuario $cajero, Caja $caja, array $items, string $medioPago = 'EFECTIVO'): Venta
    {
        return app(VentaService::class)->registrar($cajero, $medioPago, $items);
    }

    private function crearVenta(Usuario $cajero, array $items, string $medioPago = 'EFECTIVO'): Venta
    {
        $caja = $this->abrirCajaAbiertaPara($cajero);

        return $this->crearVentaEnCaja($cajero, $caja, $items, $medioPago);
    }

    private function crearVendible(
        string $nombre,
        string $unidad,
        float $monto,
        string $categoriaNombre = 'Frutas',
    ): Producto {
        $categoria = Categoria::firstOrCreate(['nombre' => $categoriaNombre], ['activa' => true]);

        $producto = Producto::create([
            'categoria_id' => $categoria->id,
            'nombre' => $nombre,
            'unidad_medida' => $unidad,
            'activo' => true,
        ]);

        Precio::create([
            'producto_id' => $producto->id,
            'monto' => $monto,
            'vigente' => true,
            'usuario_id' => null,
        ]);

        return $producto;
    }

    private function cambiarPrecio(Producto $producto, float $monto): void
    {
        $vigente = Precio::where('producto_id', $producto->id)->where('vigente', true)->first();
        $vigente?->update(['vigente' => false]);

        Precio::create([
            'producto_id' => $producto->id,
            'monto' => $monto,
            'vigente' => true,
            'usuario_id' => null,
        ]);
    }

    private function crearUsuarioConRol(string $nombreRol, array $permisos): Usuario
    {
        $rol = Rol::firstOrCreate(['nombre' => $nombreRol]);

        $rol->permisos()->sync(
            collect($permisos)
                ->unique()
                ->map(fn (string $nombre) => Permiso::firstOrCreate(['nombre' => $nombre]))
                ->pluck('id'),
        );

        return Usuario::factory()->create(['rol_id' => $rol->id]);
    }
}
