<?php

namespace Tests\Feature;

use App\Enums\EstadoCaja;
use App\Enums\TipoMovimientoCaja;
use App\Models\Caja;
use App\Models\Categoria;
use App\Models\MovimientoCaja;
use App\Models\Permiso;
use App\Models\Precio;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\CajaService;
use App\Support\Permisos as PermisosDisponibles;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CajaTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------ Acceso y permisos

    public function test_cajero_con_permiso_puede_acceder_a_la_caja(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosCaja());

        $this->actingAs($cajero)
            ->get('/caja')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Caja/Index')
                ->where('caja_abierta', false)
                ->where('resumen', null));
    }

    public function test_dueno_puede_acceder_a_la_caja(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)->get('/caja')->assertOk();
    }

    public function test_usuario_sin_permiso_recibe_403(): void
    {
        $usuario = $this->crearUsuarioConRol('OPERADOR', []);

        $this->actingAs($usuario)->get('/caja')->assertForbidden();
        $this->actingAs($usuario)
            ->post('/caja/abrir', ['monto_inicial' => 1000])
            ->assertForbidden();
    }

    // ------------------------------------------------------------------ Apertura

    public function test_abrir_caja_crea_una_caja_abierta_y_redirige(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosCaja());

        $this->actingAs($cajero)
            ->post('/caja/abrir', ['monto_inicial' => 10000])
            ->assertRedirect(route('caja.index'));

        $this->assertDatabaseHas('cajas', [
            'usuario_abre_id' => $cajero->id,
            'estado' => EstadoCaja::ABIERTA->value,
            'monto_inicial' => 10000,
        ]);

        $this->assertSame(1, Caja::count());
    }

    public function test_abrir_caja_validar_monto_inicial(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosCaja());

        $this->actingAs($cajero)
            ->post('/caja/abrir', ['monto_inicial' => -5])
            ->assertSessionHasErrors('monto_inicial');

        $this->actingAs($cajero)
            ->post('/caja/abrir', [])
            ->assertSessionHasErrors('monto_inicial');

        $this->assertSame(0, Caja::count());
    }

    public function test_no_puede_abrirse_una_segunda_caja_si_hay_una_abierta(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosCaja());
        $this->abrirCaja($cajero, 1000);

        $this->actingAs($cajero)
            ->post('/caja/abrir', ['monto_inicial' => 2000])
            ->assertSessionHasErrors('monto_inicial');

        $this->assertSame(1, Caja::count());
    }

    public function test_la_caja_abierta_se_ve_en_el_index_con_su_resumen(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosCaja());
        $this->abrirCaja($cajero, 5000);

        $this->actingAs($cajero)
            ->get('/caja')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Caja/Index')
                ->where('caja_abierta', true)
                ->where('resumen.monto_inicial', 5000)
                ->where('resumen.efectivo_esperado', 5000));
    }

    // ------------------------------------------------------------------ POS: exige caja abierta

    public function test_el_pos_expone_si_hay_caja_abierta(): void
    {
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPosCaja());

        $this->actingAs($cajero)
            ->get('/pos')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('caja_abierta', false)
                ->where('puede_abrir_caja', true));

        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->get('/pos')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('caja_abierta', true));
    }

    public function test_no_se_puede_vender_sin_caja_abierta(): void
    {
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPosCaja());

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'medio_pago' => 'EFECTIVO',
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
            ])
            ->assertSessionHasErrors('caja');

        $this->assertSame(0, Venta::count());
    }

    // ------------------------------------------------------------------ Movimientos y resumen

    public function test_la_venta_queda_asociada_a_la_caja_y_genera_movimiento_venta(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPosCaja());
        $caja = $this->abrirCaja($cajero, 1000);
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'medio_pago' => 'EFECTIVO',
                'items' => [['producto_id' => $producto->id, 'cantidad' => 2]],
            ])
            ->assertRedirect(route('pos.index'));

        $venta = Venta::where('usuario_id', $cajero->id)->first();

        $this->assertSame($caja->id, $venta->caja_id);

        $this->assertDatabaseHas('movimientos_caja', [
            'caja_id' => $caja->id,
            'venta_id' => $venta->id,
            'tipo' => TipoMovimientoCaja::VENTA->value,
            'monto' => 2400,
        ]);
    }

    public function test_registrar_ingreso_y_egreso_manual(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosCaja());
        $this->abrirCaja($cajero, 5000);

        $this->actingAs($cajero)
            ->post('/caja/movimientos', [
                'tipo' => 'INGRESO',
                'monto' => 1500,
                'concepto' => 'aporte',
            ])
            ->assertRedirect(route('caja.index'));

        $this->actingAs($cajero)
            ->post('/caja/movimientos', [
                'tipo' => 'EGRESO',
                'monto' => 700,
                'concepto' => 'gasto',
            ])
            ->assertRedirect(route('caja.index'));

        $this->assertDatabaseHas('movimientos_caja', [
            'tipo' => TipoMovimientoCaja::INGRESO->value,
            'monto' => 1500,
            'concepto' => 'aporte',
        ]);
        $this->assertDatabaseHas('movimientos_caja', [
            'tipo' => TipoMovimientoCaja::EGRESO->value,
            'monto' => 700,
            'concepto' => 'gasto',
        ]);
    }

    public function test_movimiento_manual_valida_monto_tipo_y_concepto(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosCaja());
        $this->abrirCaja($cajero, 1000);

        $this->actingAs($cajero)
            ->post('/caja/movimientos', ['tipo' => 'VENTA', 'monto' => 10, 'concepto' => 'x'])
            ->assertSessionHasErrors('tipo');

        $this->actingAs($cajero)
            ->post('/caja/movimientos', ['tipo' => 'INGRESO', 'monto' => 0, 'concepto' => 'x'])
            ->assertSessionHasErrors('monto');

        $this->actingAs($cajero)
            ->post('/caja/movimientos', ['tipo' => 'INGRESO', 'monto' => 10, 'concepto' => ''])
            ->assertSessionHasErrors('concepto');

        $this->assertSame(0, MovimientoCaja::count());
    }

    public function test_efectivo_esperado_sumando_inicial_ventas_e_ingreso_egreso(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPosCaja());
        $caja = $this->abrirCaja($cajero, 10000);
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 5000);

        // 1 venta efectivo $5000
        $this->vender($cajero, $producto, 1, 'EFECTIVO');

        $this->registrarManual($cajero, 'INGRESO', 2000, 'cambio');
        $this->registrarManual($cajero, 'EGRESO', 1000, 'gasto');

        $resumen = app(CajaService::class)->resumen($caja);

        // 10000 + 5000 + 2000 - 1000 = 16000
        $this->assertSame(16000.0, $resumen['efectivo_esperado']);
        $this->assertSame(5000.0, $resumen['ventas_efectivo']);
        $this->assertSame(2000.0, $resumen['ingresos']);
        $this->assertSame(1000.0, $resumen['egresos']);
        $this->assertSame(5000.0, $resumen['total_ventas']);
        $this->assertSame(1, $resumen['cantidad_ventas']);
    }

    public function test_el_efectivo_esperado_no_incluye_ventas_por_transferencia(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPosCaja());
        $caja = $this->abrirCaja($cajero, 10000);
        $gaseosa = $this->crearVendible('Gaseosa', 'UNIDAD', 3000);
        $papa = $this->crearVendible('Papa', 'KILOGRAMO', 2000);

        // efectivo $3000 y transferencia $4000 (0.5*... no, papa 2kg -> 4000)
        $this->vender($cajero, $gaseosa, 1, 'EFECTIVO');
        $this->vender($cajero, $papa, 2, 'TRANSFERENCIA');

        $resumen = app(CajaService::class)->resumen($caja);

        $this->assertSame(7000.0, $resumen['total_ventas']);
        $this->assertSame(3000.0, $resumen['ventas_efectivo']);
        $this->assertSame(4000.0, $resumen['ventas_transferencia']);
        // Sólo el efectivo físico cuenta en el esperado
        $this->assertSame(13000.0, $resumen['efectivo_esperado']);
    }

    public function test_desglose_por_medio_de_pago(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPosCaja());
        $caja = $this->abrirCaja($cajero, 0);
        $gaseosa = $this->crearVendible('Gaseosa', 'UNIDAD', 1000);
        $papa = $this->crearVendible('Papa', 'KILOGRAMO', 2000);

        $this->vender($cajero, $gaseosa, 2, 'EFECTIVO');         // 2000 efectivo
        $this->vender($cajero, $gaseosa, 1, 'TARJETA');          // 1000 tarjeta
        $this->vender($cajero, $papa, 1.5, 'TRANSFERENCIA');     // 3000 transferencia

        $resumen = app(CajaService::class)->resumen($caja);

        $this->assertSame(2000.0, $resumen['ventas_efectivo']);
        $this->assertSame(1000.0, $resumen['ventas_tarjeta']);
        $this->assertSame(3000.0, $resumen['ventas_transferencia']);
        $this->assertSame(6000.0, $resumen['total_ventas']);
        $this->assertSame(3, $resumen['cantidad_ventas']);
        $this->assertSame(2000.0, $resumen['efectivo_esperado']);
    }

    // ------------------------------------------------------------------ Cierre

    public function test_cerrar_caja_guarda_contado_esperado_y_diferencia(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosCaja());
        $caja = $this->abrirCaja($cajero, 10000);
        $this->registrarManual($cajero, 'INGRESO', 500, 'cambio');

        $this->actingAs($cajero)
            ->post('/caja/cerrar', ['efectivo_contado' => 11000])
            ->assertRedirect(route('caja.index'));

        $caja->refresh();

        $this->assertSame(EstadoCaja::CERRADA->value, $caja->estado->value);
        $this->assertSame(11000.0, (float) $caja->efectivo_contado);
        $this->assertSame(10500.0, (float) $caja->efectivo_esperado);
        $this->assertSame(500.0, (float) $caja->diferencia);
        $this->assertNotNull($caja->cerrada_en);
    }

    public function test_cerrar_caja_con_diferencia_negativa(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosCaja());
        $caja = $this->abrirCaja($cajero, 10000);

        $this->actingAs($cajero)
            ->post('/caja/cerrar', ['efectivo_contado' => 9500])
            ->assertRedirect(route('caja.index'));

        $caja->refresh();
        $this->assertSame(EstadoCaja::CERRADA->value, $caja->estado->value);
        $this->assertSame(-500.0, (float) $caja->diferencia);
    }

    public function test_cerrar_caja_valida_efectivo_contado(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosCaja());
        $this->abrirCaja($cajero, 1000);

        $this->actingAs($cajero)
            ->post('/caja/cerrar', ['efectivo_contado' => -5])
            ->assertSessionHasErrors('efectivo_contado');

        $caja = Caja::first();
        $this->assertSame(EstadoCaja::ABIERTA->value, $caja->estado->value);
    }

    public function test_despues_de_cerrar_no_se_puede_vender_ni_registrar_movimientos(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPosCaja());
        $caja = $this->abrirCaja($cajero, 1000);
        $movsAntes = MovimientoCaja::count();
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);

        $this->actingAs($cajero)
            ->post('/caja/cerrar', ['efectivo_contado' => 1000])
            ->assertRedirect(route('caja.index'));

        // Sin caja abierta no se puede vender.
        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'medio_pago' => 'EFECTIVO',
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
            ])
            ->assertSessionHasErrors('caja');

        // Sin caja abierta el movimiento se descarta y solo redirige.
        $this->actingAs($cajero)
            ->post('/caja/movimientos', ['tipo' => 'INGRESO', 'monto' => 100, 'concepto' => 'x'])
            ->assertRedirect(route('caja.index'));

        $this->assertSame(0, Venta::count());
        $this->assertSame($movsAntes, MovimientoCaja::count());
    }

    public function test_no_se_puede_cerrar_dos_veces_la_misma_caja(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosCaja());
        $caja = $this->abrirCaja($cajero, 1000);

        $this->actingAs($cajero)
            ->post('/caja/cerrar', ['efectivo_contado' => 1000])
            ->assertRedirect(route('caja.index'));

        // Tras el primer cierre ya no existe caja abierta: el segundo intento
        // se maneja como "sin caja abierta" sin tocar la fila ya cerrada.
        $this->actingAs($cajero)
            ->post('/caja/cerrar', ['efectivo_contado' => 9999])
            ->assertRedirect(route('caja.index'));

        $caja->refresh();
        $this->assertSame(EstadoCaja::CERRADA->value, $caja->estado->value);
        $this->assertSame(1000.0, (float) $caja->efectivo_contado);
    }

    public function test_solo_puede_haber_una_caja_abierta_a_nivel_de_base(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosCaja());
        $this->abrirCaja($cajero, 1000);

        $this->expectException(QueryException::class);
        $this->abrirCajaDirecta($cajero, 2000);
    }

    // ------------------------------------------------------------------ Helpers

    private function abrirCaja(Usuario $cajero, float $montoInicial = 0.0): Caja
    {
        return app(CajaService::class)->abrir($cajero, $montoInicial);
    }

    private function abrirCajaDirecta(Usuario $cajero, float $montoInicial): Caja
    {
        return Caja::create([
            'usuario_abre_id' => $cajero->id,
            'estado' => EstadoCaja::ABIERTA,
            'monto_inicial' => $montoInicial,
            'abierta_en' => now(),
        ]);
    }

    private function registrarManual(Usuario $cajero, string $tipo, float $monto, string $concepto): void
    {
        $this->actingAs($cajero)
            ->post('/caja/movimientos', [
                'tipo' => $tipo,
                'monto' => $monto,
                'concepto' => $concepto,
            ])
            ->assertRedirect(route('caja.index'));
    }

    private function vender(Usuario $cajero, Producto $producto, float $cantidad, string $medioPago): Venta
    {
        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'medio_pago' => $medioPago,
                'items' => [['producto_id' => $producto->id, 'cantidad' => $cantidad]],
            ])
            ->assertRedirect(route('pos.index'));

        return Venta::query()->where('usuario_id', $cajero->id)->latest('id')->first();
    }

    private function crearVendible(
        string $nombre,
        string $unidad,
        float $monto,
        bool $activo = true,
        string $categoriaNombre = 'Frutas',
        bool $categoriaActiva = true,
    ): Producto {
        $categoria = Categoria::firstOrCreate(['nombre' => $categoriaNombre], ['activa' => $categoriaActiva]);

        $producto = Producto::create([
            'categoria_id' => $categoria->id,
            'nombre' => $nombre,
            'unidad_medida' => $unidad,
            'activo' => $activo,
        ]);

        Precio::create([
            'producto_id' => $producto->id,
            'monto' => $monto,
            'vigente' => true,
        ]);

        return $producto;
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
    private function permisosPosCaja(): array
    {
        return array_values(array_unique(
            array_merge($this->permisosCaja(), $this->permisosPos()),
        ));
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
