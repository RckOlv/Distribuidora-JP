<?php

namespace Tests\Feature;

use App\Enums\AccionAuditoria;
use App\Enums\EstadoCaja;
use App\Enums\EstadoPagoVenta;
use App\Enums\TipoMovimientoCaja;
use App\Enums\TipoTrabajoImpresion;
use App\Models\Auditoria;
use App\Models\Caja;
use App\Models\Categoria;
use App\Models\DetalleVenta;
use App\Models\MovimientoCaja;
use App\Models\Permiso;
use App\Models\Precio;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Ticket;
use App\Models\TrabajoImpresion;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\CajaService;
use App\Support\Permisos as PermisosDisponibles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CorreccionesTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------ Cierre de caja: observación

    public function test_cerrar_caja_con_diferencia_sin_observacion_es_rechazado(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosCaja());
        $caja = app(CajaService::class)->abrir($cajero, 10000);
        app(CajaService::class)->registrarManual($caja, $cajero, TipoMovimientoCaja::INGRESO, 500, 'cambio');

        $this->actingAs($cajero)
            ->post('/caja/cerrar', ['efectivo_contado' => 11000])
            ->assertSessionHasErrors('observacion');

        $this->assertSame(EstadoCaja::ABIERTA->value, $caja->refresh()->estado->value);
    }

    public function test_cerrar_caja_sin_diferencia_no_exige_observacion(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosCaja());
        $caja = app(CajaService::class)->abrir($cajero, 10000);

        $this->actingAs($cajero)
            ->post('/caja/cerrar', ['efectivo_contado' => 10000])
            ->assertRedirect(route('caja.index'));

        $this->assertSame(EstadoCaja::CERRADA->value, $caja->refresh()->estado->value);
        $this->assertNull($caja->observacion_cierre);
    }

    public function test_cerrar_caja_con_diferencia_guarda_observacion(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosCaja());
        $caja = app(CajaService::class)->abrir($cajero, 10000);

        $this->actingAs($cajero)
            ->post('/caja/cerrar', [
                'efectivo_contado' => 9500,
                'observacion' => 'Faltante al contar el vuelto',
            ])
            ->assertRedirect(route('caja.index'));

        $this->assertSame('Faltante al contar el vuelto', $caja->refresh()->observacion_cierre);
        $this->assertSame(-500.0, (float) $caja->diferencia);
    }

    // ------------------------------------------------------------------ Kilogramo: precio_por_kg × peso

    public function test_kilogramo_calcula_precio_por_kg_por_peso_entero(): void
    {
        $this->assertKgBackend(1.0, 1500.0);
    }

    public function test_kilogramo_calcula_precio_por_kg_por_peso_decimal(): void
    {
        $this->assertKgBackend(1.5, 2250.0);
    }

    public function test_kilogramo_calcula_precio_por_kg_por_peso_tres_decimales(): void
    {
        $this->assertKgBackend(1.850, 2775.0);
    }

    private function assertKgBackend(float $pesoKg, float $esperado): void
    {
        $producto = $this->crearVendible('Tomate', 'KILOGRAMO', 1500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        app(CajaService::class)->abrir($cajero, 0);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'medio_pago' => 'EFECTIVO',
                'items' => [['producto_id' => $producto->id, 'cantidad' => $pesoKg]],
                'efectivo_recibido' => $esperado,
            ])
            ->assertRedirect(route('pos.index'));

        $detalle = DetalleVenta::query()->latest('id')->first();

        $this->assertNotNull($detalle);
        $this->assertSame(1500.0, (float) $detalle->precio_unitario);
        $this->assertSame($pesoKg, (float) $detalle->cantidad);
        $this->assertSame($esperado, (float) $detalle->subtotal);
        $this->assertSame($esperado, (float) $detalle->venta->total);
    }

    // ------------------------------------------------------------------ Flujo de pago pendiente (transferencia/tarjeta)

    public function test_pendiente_crea_venta_sin_cerrar_ni_contabilizar(): void
    {
        $producto = $this->crearVendible('Manzana', 'UNIDAD', 500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        app(CajaService::class)->abrir($cajero, 0);

        $this->actingAs($cajero)
            ->post(route('pos.ventas.pendiente'), [
                'medio_pago' => 'TRANSFERENCIA',
                'items' => [['producto_id' => $producto->id, 'cantidad' => 2]],
            ])
            ->assertRedirect(route('pos.index'));

        $venta = Venta::query()->latest('id')->first();

        $this->assertSame(EstadoPagoVenta::PENDIENTE_PAGO->value, $venta->estado_pago->value);
        $this->assertSame(1000.0, (float) $venta->total);
        $this->assertSame(1, DetalleVenta::count());
        $this->assertSame(0, MovimientoCaja::count(), 'No hay movimiento de caja hasta confirmar.');
        $this->assertSame(0, Ticket::count(), 'No hay ticket hasta confirmar.');
        $this->assertSame(0, TrabajoImpresion::count(), 'No hay impresión hasta confirmar.');
        $this->assertFalse(Auditoria::where('accion', AccionAuditoria::VENTA_REALIZADA->value)->exists(), 'No hay auditoría de venta hasta confirmar.');
    }

    public function test_pendiente_solo_acepta_transferencia_o_tarjeta(): void
    {
        $producto = $this->crearVendible('Manzana', 'UNIDAD', 500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        app(CajaService::class)->abrir($cajero, 0);

        $this->actingAs($cajero)
            ->post(route('pos.ventas.pendiente'), [
                'medio_pago' => 'EFECTIVO',
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
            ])
            ->assertSessionHasErrors('medio_pago');

        $this->assertSame(0, Venta::count());
    }

    public function test_confirmar_pago_completa_la_venta_pendiente(): void
    {
        $producto = $this->crearVendible('Manzana', 'UNIDAD', 500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $caja = app(CajaService::class)->abrir($cajero, 0);

        $this->actingAs($cajero)
            ->post(route('pos.ventas.pendiente'), [
                'medio_pago' => 'TARJETA',
                'items' => [['producto_id' => $producto->id, 'cantidad' => 3]],
            ])
            ->assertRedirect(route('pos.index'));

        $venta = Venta::query()->latest('id')->first();

        $this->actingAs($cajero)
            ->post(route('pos.ventas.confirmar', $venta->id))
            ->assertRedirect(route('pos.index'));

        $this->assertSame(EstadoPagoVenta::PAGADA->value, $venta->refresh()->estado_pago->value);
        $this->assertSame(1, MovimientoCaja::count());
        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, TrabajoImpresion::where('tipo', TipoTrabajoImpresion::VENTA->value)->count());
        $this->assertTrue(Auditoria::where('accion', AccionAuditoria::VENTA_REALIZADA->value)->exists());
        $this->assertSame($caja->id, $venta->caja_id);
    }

    public function test_confirmar_pago_es_idempotente(): void
    {
        $producto = $this->crearVendible('Manzana', 'UNIDAD', 500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        app(CajaService::class)->abrir($cajero, 0);

        $this->actingAs($cajero)
            ->post(route('pos.ventas.pendiente'), [
                'medio_pago' => 'TRANSFERENCIA',
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
            ]);

        $venta = Venta::query()->latest('id')->first();

        $this->actingAs($cajero)->post(route('pos.ventas.confirmar', $venta->id))->assertRedirect();
        $this->actingAs($cajero)->post(route('pos.ventas.confirmar', $venta->id))->assertRedirect();

        $this->assertSame(1, Venta::count(), 'No se duplica la venta.');
        $this->assertSame(1, MovimientoCaja::count(), 'No se duplica el movimiento.');
        $this->assertSame(1, Ticket::count(), 'No se duplica el ticket.');
        $this->assertSame(1, TrabajoImpresion::where('tipo', TipoTrabajoImpresion::VENTA->value)->count());
    }

    public function test_cancelar_pendiente_elimina_venta_y_detalles(): void
    {
        $producto = $this->crearVendible('Manzana', 'UNIDAD', 500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        app(CajaService::class)->abrir($cajero, 0);

        $this->actingAs($cajero)->post(route('pos.ventas.pendiente'), [
            'medio_pago' => 'TRANSFERENCIA',
            'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ]);

        $venta = Venta::query()->latest('id')->first();

        $this->actingAs($cajero)
            ->post(route('pos.ventas.cancelar', $venta->id))
            ->assertRedirect(route('pos.index'));

        $this->assertSame(0, Venta::count());
        $this->assertSame(0, DetalleVenta::count());
        $this->assertSame(0, MovimientoCaja::count());
    }

    // ------------------------------------------------------------------ Filtrado de pendientes

    public function test_ventas_pendientes_no_aparecen_en_historial(): void
    {
        $producto = $this->crearVendible('Manzana', 'UNIDAD', 500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        app(CajaService::class)->abrir($cajero, 0);

        $this->actingAs($cajero)->post(route('pos.ventas.pendiente'), [
            'medio_pago' => 'TARJETA',
            'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ]);

        $this->actingAs($cajero)->get(route('ventas.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Ventas/Index')
                ->has('ventas.data', 0));

        $this->actingAs($cajero)->post(route('pos.ventas.confirmar', Venta::query()->latest('id')->first()->id));

        $this->actingAs($cajero)->get(route('ventas.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Ventas/Index')
                ->has('ventas.data', 1));

        $this->assertDatabaseHas('ventas', ['estado_pago' => EstadoPagoVenta::PAGADA->value]);
    }

    // ------------------------------------------------------------------ Validaciones de categoría

    public function test_categoria_nombre_muy_corto_es_rechazado_en_espanol(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->post('/categorias', ['nombre' => 'A'])
            ->assertSessionHasErrors('nombre');

        $this->actingAs($dueno)->get('/categorias')->assertOk();
    }

    public function test_categoria_solo_numeros_es_rechazada(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->post('/categorias', ['nombre' => '12345'])
            ->assertSessionHasErrors('nombre');

        $this->assertSame(0, Categoria::count());
    }

    public function test_categoria_duplicada_case_insensitive_es_rechazada(): void
    {
        Categoria::create(['nombre' => 'Verduras', 'activa' => true]);
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->post('/categorias', ['nombre' => 'verduras'])
            ->assertSessionHasErrors('nombre');

        $this->assertSame(1, Categoria::count());
    }

    public function test_categoria_con_solo_letras_acentuadas_es_valida(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->post('/categorias', ['nombre' => 'Ñoños'])
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('categorias', ['nombre' => 'Ñoños']);
    }

    public function test_categoria_doble_espacio_al_inicio_es_normalizada(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->post('/categorias', ['nombre' => '   Frutas   '])
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('categorias', ['nombre' => 'Frutas']);
    }

    // ------------------------------------------------------------------ Verificación de nombre de producto

    public function test_verificar_nombre_devuelve_existe_false_cuando_no_esta_repetido(): void
    {
        $categoria = $this->crearCategoria('Frutas');
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->getJson('/productos/verificar-nombre?nombre=Zapallo')
            ->assertOk()
            ->assertJson(['existe' => false]);
    }

    public function test_verificar_nombre_devuelve_existe_true_cuando_esta_repetido(): void
    {
        $this->crearVendible('Tomate', 'KILOGRAMO', 1500);
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->getJson('/productos/verificar-nombre?nombre='.rawurlencode('  TOMATE  '))
            ->assertOk()
            ->assertJson(['existe' => true]);
    }

    public function test_verificar_nombre_ignora_el_producto_que_se_edita(): void
    {
        $producto = $this->crearVendible('Manzana', 'UNIDAD', 500);
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->getJson('/productos/verificar-nombre?nombre=Manzana&excepto='.$producto->id)
            ->assertOk()
            ->assertJson(['existe' => false]);
    }

    // ------------------------------------------------------------------ Exportación PDF de productos

    public function test_exportar_productos_a_pdf_requiere_permiso_y_genera_pdf(): void
    {
        $this->crearVendible('Tomate', 'KILOGRAMO', 1500);
        $cajero = $this->crearUsuarioConRol(
            Rol::CAJERO,
            [PermisosDisponibles::PRODUCTOS_VER],
        );

        $this->actingAs($cajero)
            ->get('/productos/exportar-pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_exportar_productos_a_pdf_respeta_filtro_de_categoria(): void
    {
        $categoria = $this->crearCategoria('Frutas');
        $this->crearVendible('Manzana', 'UNIDAD', 500);
        Producto::create([
            'categoria_id' => $categoria->id,
            'nombre' => 'Pera',
            'unidad_medida' => 'UNIDAD',
            'activo' => true,
        ]);

        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->get('/productos/exportar-pdf?categoria_id='.$categoria->id)
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    // ------------------------------------------------------------------ Acceso solo lectura del cajero a catálogo

    public function test_cajero_con_permiso_puede_ver_productos_pero_no_crearlos(): void
    {
        $cajero = $this->crearUsuarioConRol(
            Rol::CAJERO,
            [PermisosDisponibles::PRODUCTOS_VER],
        );
        $categoria = $this->crearCategoria('Frutas');
        $this->crearVendible('Manzana', 'UNIDAD', 500);

        $this->actingAs($cajero)
            ->get('/productos')
            ->assertOk();

        $this->actingAs($cajero)
            ->post('/productos', [
                'nombre' => 'Pera',
                'categoria_id' => $categoria->id,
                'unidad_medida' => 'UNIDAD',
                'monto' => 600,
            ])
            ->assertStatus(403);
    }

    public function test_cajero_sin_permiso_no_puede_ver_productos(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, []);

        $this->actingAs($cajero)
            ->get('/productos')
            ->assertForbidden();
    }

    // ------------------------------------------------------------------ Validaciones de producto

    public function test_producto_nombre_duplicado_case_insensitive_es_rechazado(): void
    {
        $producto = $this->crearVendible('Tomate', 'KILOGRAMO', 1500);
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->post('/productos', [
                'nombre' => 'tomate',
                'categoria_id' => $producto->categoria_id,
                'unidad_medida' => 'KILOGRAMO',
                'codigo' => '7790000800',
                'monto' => 2000,
                'costo' => 1200,
            ])
            ->assertSessionHasErrors('nombre');
    }

    public function test_producto_codigo_duplicado_case_insensitive_es_rechazado(): void
    {
        $producto = $this->crearVendible('Manzana', 'UNIDAD', 500, 'ABC-123');
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->post('/productos', [
                'nombre' => 'Pera',
                'categoria_id' => $producto->categoria_id,
                'unidad_medida' => 'UNIDAD',
                'monto' => 600,
                'codigo' => 'abc-123',
            ])
            ->assertSessionHasErrors('codigo');
    }

    public function test_producto_sin_codigo_es_rechazado(): void
    {
        $categoria = Categoria::create(['nombre' => 'Verduras', 'activa' => true]);
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->post('/productos', [
                'nombre' => 'Cebolla',
                'categoria_id' => $categoria->id,
                'unidad_medida' => 'KILOGRAMO',
                'monto' => 700,
                'costo' => 400,
            ])
            ->assertSessionHasErrors('codigo');

        $this->assertDatabaseMissing('productos', ['nombre' => 'Cebolla']);
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

    /**
     * @return list<string>
     */
    private function permisosCaja(): array
    {
        return [
            PermisosDisponibles::CAJAS_USAR,
            PermisosDisponibles::CAJAS_VER,
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
    ): Producto {
        $categoria = $this->crearCategoria('Frutas');

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
