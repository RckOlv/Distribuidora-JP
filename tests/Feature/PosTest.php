<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Categoria;
use App\Models\DetalleVenta;
use App\Models\Permiso;
use App\Models\Precio;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\CajaService;
use App\Support\Permisos as PermisosDisponibles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PosTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------ Permisos y acceso

    public function test_cajero_puede_acceder_al_pos(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        $this->actingAs($cajero)
            ->get('/pos')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Pos/Index'));
    }

    public function test_dueno_puede_acceder_al_pos(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)->get('/pos')->assertOk();
    }

    public function test_usuario_sin_permiso_recibe_403(): void
    {
        $usuario = $this->crearUsuarioConRol('OPERADOR', []);

        $this->actingAs($usuario)->get('/pos')->assertForbidden();
        $this->actingAs($usuario)->post('/pos/ventas', [
            'medio_pago' => 'EFECTIVO',
            'items' => [],
        ])->assertForbidden();
    }

    public function test_pos_exige_tambien_permiso_de_realizar_ventas_al_confirmar(): void
    {
        $usuario = $this->crearUsuarioConRol(Rol::CAJERO, [PermisosDisponibles::POS_USAR]);

        $this->actingAs($usuario)
            ->post('/pos/ventas', [
                'medio_pago' => 'EFECTIVO',
                'items' => [['producto_id' => 1, 'cantidad' => 1]],
            ])
            ->assertForbidden();
    }

    public function test_cajero_sigue_sin_acceso_al_abm_de_productos_y_categorias(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1500);

        $this->actingAs($cajero)->get('/productos')->assertForbidden();
        $this->actingAs($cajero)->get('/productos/crear')->assertForbidden();
        $this->actingAs($cajero)->get('/categorias')->assertForbidden();
        $this->actingAs($cajero)
            ->put("/productos/{$producto->id}", ['nombre' => 'x'])
            ->assertForbidden();
    }

    // ------------------------------------------------------------------ Catálogo del POS

    public function test_pos_expone_solo_productos_activos(): void
    {
        $this->crearVendible('Papa', 'KILOGRAMO', 1500);
        $this->crearVendible('Cilantro', 'KILOGRAMO', 2000, null, false);

        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->get('/pos')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Pos/Index')
                ->has('productos', 1));
    }

    public function test_pos_excluye_productos_de_categorias_inactivas(): void
    {
        $this->crearVendible('Congelados', 'UNIDAD', 500, null, true, 'Congelados', false);
        $activo = $this->crearVendible('Lechuga', 'UNIDAD', 1200);

        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->get('/pos')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Pos/Index')
                ->has('productos', 1)
                ->where('productos.0.id', $activo->id));
    }

    public function test_pos_expone_codigo_imagen_y_precio_vigente(): void
    {
        $producto = $this->crearVendible('Bolsa Sopa', 'BOLSA', 4000, '7790000000001');

        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->get('/pos')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('productos.0.id', $producto->id)
                ->where('productos.0.codigo', '7790000000001')
                ->where('productos.0.precio', '4000.00')
                ->where('productos.0.unidad_medida', 'BOLSA')
                ->where('productos.0.categoria_nombre', 'Frutas'));
    }

    public function test_scope_buscar_encuentra_por_nombre(): void
    {
        $this->crearVendible('Banana', 'KILOGRAMO', 2500);
        $this->crearVendible('Papa', 'KILOGRAMO', 1500);

        $resultados = Producto::buscar('papa')->pluck('nombre');

        $this->assertContains('Papa', $resultados);
        $this->assertNotContains('Banana', $resultados);
    }

    public function test_scope_buscar_encuentra_por_barcode(): void
    {
        $this->crearVendible('Bolsa Sopa', 'BOLSA', 4000, '7790000000001');

        $resultados = Producto::buscar('7790000000001');

        $this->assertSame(1, $resultados->count());
        $this->assertSame('Bolsa Sopa', $resultados->first()->nombre);
    }

    public function test_scope_buscar_con_codigo_inexistente_no_devuelve_resultados(): void
    {
        $this->crearVendible('Bolsa Sopa', 'BOLSA', 4000, '7790000000001');

        $this->assertSame(0, Producto::buscar('9999999999999')->count());
    }

    // ------------------------------------------------------------------ Registro de ventas

    public function test_crear_venta_simple(): void
    {
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero, 5000);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'medio_pago' => 'EFECTIVO',
                'items' => [['producto_id' => $producto->id, 'cantidad' => 2]],
            ])
            ->assertRedirect(route('pos.index'));

        $this->assertDatabaseHas('ventas', [
            'usuario_id' => $cajero->id,
            'medio_pago' => 'EFECTIVO',
            'total' => 2400,
        ]);

        $venta = Venta::where('usuario_id', $cajero->id)->first();

        $this->assertDatabaseHas('detalles_venta', [
            'venta_id' => $venta->id,
            'producto_id' => $producto->id,
            'cantidad' => 2,
            'precio_unitario' => 1200,
            'subtotal' => 2400,
        ]);
    }

    public function test_venta_con_multiples_productos(): void
    {
        $papa = $this->crearVendible('Papa', 'KILOGRAMO', 1500);
        $gaseosa = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'medio_pago' => 'TARJETA',
                'items' => [
                    ['producto_id' => $papa->id, 'cantidad' => 1.5],
                    ['producto_id' => $gaseosa->id, 'cantidad' => 3],
                ],
            ])
            ->assertRedirect(route('pos.index'));

        $venta = Venta::query()->where('usuario_id', $cajero->id)->first();

        // 1.5 * 1500 = 2250 ; 3 * 1200 = 3600 ; total = 5850
        $this->assertDatabaseHas('ventas', ['id' => $venta->id, 'total' => 5850]);
        $this->assertSame(2, $venta->detalles()->count());
    }

    public function test_venta_acepta_cantidad_decimal_para_kilogramo(): void
    {
        $papa = $this->crearVendible('Papa', 'KILOGRAMO', 1500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'medio_pago' => 'EFECTIVO',
                'items' => [['producto_id' => $papa->id, 'cantidad' => 0.5]],
            ])
            ->assertRedirect(route('pos.index'));

        $venta = Venta::query()->where('usuario_id', $cajero->id)->first();

        $this->assertDatabaseHas('detalles_venta', [
            'venta_id' => $venta->id,
            'cantidad' => 0.5,
            'subtotal' => 750,
        ]);
    }

    public function test_se_rechaza_cantidad_decimal_para_unidad_y_bolsa(): void
    {
        $productoUnidad = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $productoBolsa = $this->crearVendible('Bolsa Sopa', 'BOLSA', 4000);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'medio_pago' => 'EFECTIVO',
                'items' => [['producto_id' => $productoUnidad->id, 'cantidad' => 1.5]],
            ])
            ->assertSessionHasErrors('items.0.cantidad');

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'medio_pago' => 'EFECTIVO',
                'items' => [['producto_id' => $productoBolsa->id, 'cantidad' => 2.25]],
            ])
            ->assertSessionHasErrors('items.0.cantidad');

        $this->assertSame(0, Venta::count());
    }

    public function test_se_rechazan_cantidades_menores_o_iguales_a_cero(): void
    {
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        foreach ([0, -1, -0.5] as $cantidad) {
            $this->actingAs($cajero)
                ->post('/pos/ventas', [
                    'medio_pago' => 'EFECTIVO',
                    'items' => [['producto_id' => $producto->id, 'cantidad' => $cantidad]],
                ])
                ->assertSessionHasErrors('items.0.cantidad');
        }

        $this->assertSame(0, Venta::count());
    }

    public function test_se_rechaza_carrito_vacio(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        $this->actingAs($cajero)
            ->post('/pos/ventas', ['medio_pago' => 'EFECTIVO', 'items' => []])
            ->assertSessionHasErrors('items');

        $this->actingAs($cajero)
            ->post('/pos/ventas', ['medio_pago' => 'EFECTIVO'])
            ->assertSessionHasErrors('items');

        $this->assertSame(0, Venta::count());
    }

    public function test_se_rechaza_producto_inexistente(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'medio_pago' => 'EFECTIVO',
                'items' => [['producto_id' => 99999, 'cantidad' => 1]],
            ])
            ->assertSessionHasErrors('items.0.producto_id');

        $this->assertSame(0, Venta::count());
    }

    public function test_no_puede_venderse_producto_inactivo(): void
    {
        $inactivo = $this->crearVendible('Cilantro', 'KILOGRAMO', 2000, null, false);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'medio_pago' => 'EFECTIVO',
                'items' => [['producto_id' => $inactivo->id, 'cantidad' => 1]],
            ])
            ->assertSessionHasErrors('items.0.producto_id');

        $this->assertSame(0, Venta::count());
    }

    public function test_no_puede_venderse_producto_de_categoria_inactiva(): void
    {
        $producto = $this->crearVendible('Congelados', 'UNIDAD', 500, null, true, 'Congelados', false);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'medio_pago' => 'EFECTIVO',
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
            ])
            ->assertSessionHasErrors('items.0.producto_id');

        $this->assertSame(0, Venta::count());
    }

    public function test_no_puede_venderse_producto_sin_precio_vigente(): void
    {
        $categoria = $this->crearCategoria('Frutas');
        $sinPrecio = Producto::create([
            'categoria_id' => $categoria->id,
            'nombre' => 'Banana',
            'unidad_medida' => 'KILOGRAMO',
        ]);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'medio_pago' => 'EFECTIVO',
                'items' => [['producto_id' => $sinPrecio->id, 'cantidad' => 1]],
            ])
            ->assertSessionHasErrors('items.0.producto_id');

        $this->assertSame(0, Venta::count());
    }

    // ------------------------------------------------------------------ Precio: autoridad del backend

    public function test_el_backend_usa_el_precio_vigente_ignorando_el_enviado(): void
    {
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'medio_pago' => 'EFECTIVO',
                'items' => [
                    [
                        'producto_id' => $producto->id,
                        'cantidad' => 2,
                        'precio' => 1,
                        'subtotal' => 2,
                    ],
                ],
            ])
            ->assertRedirect(route('pos.index'));

        $venta = Venta::query()->where('usuario_id', $cajero->id)->first();

        $this->assertDatabaseHas('detalles_venta', [
            'venta_id' => $venta->id,
            'precio_unitario' => 1200,
            'subtotal' => 2400,
        ]);
        $this->assertDatabaseHas('ventas', ['id' => $venta->id, 'total' => 2400]);
    }

    public function test_cambio_de_precio_antes_de_confirmar_usa_el_vigente_del_backend(): void
    {
        $producto = $this->crearVendible('Banana', 'KILOGRAMO', 1000);
        $this->cambiarPrecio($producto, 1300);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'medio_pago' => 'EFECTIVO',
                'items' => [['producto_id' => $producto->id, 'cantidad' => 2]],
            ])
            ->assertRedirect(route('pos.index'));

        $venta = Venta::query()->where('usuario_id', $cajero->id)->first();

        $this->assertDatabaseHas('detalles_venta', [
            'venta_id' => $venta->id,
            'precio_unitario' => 1300,
            'subtotal' => 2600,
        ]);
    }

    public function test_el_precio_unitario_es_un_snapshot_que_no_se_altera_despues(): void
    {
        $producto = $this->crearVendible('Banana', 'KILOGRAMO', 2500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'medio_pago' => 'EFECTIVO',
                'items' => [['producto_id' => $producto->id, 'cantidad' => 2]],
            ])
            ->assertRedirect(route('pos.index'));

        $venta = Venta::query()->where('usuario_id', $cajero->id)->first();

        $this->assertDatabaseHas('detalles_venta', [
            'venta_id' => $venta->id,
            'precio_unitario' => 2500,
            'subtotal' => 5000,
        ]);

        $this->cambiarPrecio($producto, 3000);

        $this->assertDatabaseHas('detalles_venta', [
            'venta_id' => $venta->id,
            'precio_unitario' => 2500,
            'subtotal' => 5000,
        ]);

        $this->assertDatabaseHas('ventas', ['id' => $venta->id, 'total' => 5000]);
    }

    // ------------------------------------------------------------------ Medio de pago

    public function test_medio_de_pago_invalido_es_rechazado(): void
    {
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'medio_pago' => 'CREDITO',
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
            ])
            ->assertSessionHasErrors('medio_pago');

        $this->assertSame(0, Venta::count());
    }

    // ------------------------------------------------------------------ Transacción

    public function test_si_un_detalle_falla_la_venta_no_queda_parcialmente_creada(): void
    {
        $valido = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $inactivo = $this->crearVendible('Cilantro', 'KILOGRAMO', 2000, null, false);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'medio_pago' => 'EFECTIVO',
                'items' => [
                    ['producto_id' => $valido->id, 'cantidad' => 1],
                    ['producto_id' => $inactivo->id, 'cantidad' => 1],
                ],
            ])
            ->assertSessionHasErrors('items.1.producto_id');

        $this->assertSame(0, Venta::count());
        $this->assertSame(0, DetalleVenta::count());
    }

    // ------------------------------------------------------------------ Helpers

    /**
     * @return list<string>
     */
    private function permisosPos(): array
    {
        return [
            PermisosDisponibles::POS_USAR,
            PermisosDisponibles::VENTAS_REALIZAR,
            PermisosDisponibles::VENTAS_VER,
            PermisosDisponibles::TICKETS_IMPRIMIR,
        ];
    }

    private function crearCategoria(string $nombre, bool $activa = true): Categoria
    {
        return Categoria::firstOrCreate(['nombre' => $nombre], ['activa' => $activa]);
    }

    private function crearVendible(
        string $nombre,
        string $unidad,
        float $monto,
        ?string $codigo = null,
        bool $activo = true,
        string $categoriaNombre = 'Frutas',
        bool $categoriaActiva = true,
    ): Producto {
        $categoria = $this->crearCategoria($categoriaNombre, $categoriaActiva);

        $producto = Producto::create([
            'categoria_id' => $categoria->id,
            'nombre' => $nombre,
            'unidad_medida' => $unidad,
            'codigo' => $codigo,
            'activo' => $activo,
        ]);

        Precio::create([
            'producto_id' => $producto->id,
            'monto' => $monto,
            'vigente' => true,
        ]);

        return $producto;
    }

    private function abrirCaja(Usuario $cajero, float $montoInicial = 0.0): Caja
    {
        return app(CajaService::class)->abrir($cajero, $montoInicial);
    }

    private function cambiarPrecio(Producto $producto, float $monto): void
    {
        $vigente = Precio::where('producto_id', $producto->id)
            ->where('vigente', true)
            ->first();

        $vigente?->update(['vigente' => false]);

        Precio::create([
            'producto_id' => $producto->id,
            'monto' => $monto,
            'vigente' => true,
        ]);
    }

    private function crearUsuarioConRol(string $nombreRol, array $permisos): Usuario
    {
        $rol = Rol::factory()->create(['nombre' => $nombreRol]);

        $rol->permisos()->sync(
            collect($permisos)
                ->unique()
                ->map(fn (string $nombre) => Permiso::firstOrCreate(['nombre' => $nombre]))
                ->pluck('id'),
        );

        return Usuario::factory()->create(['rol_id' => $rol->id]);
    }
}
