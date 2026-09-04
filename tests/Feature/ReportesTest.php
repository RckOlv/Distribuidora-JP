<?php

namespace Tests\Feature;

use App\Enums\TipoMovimientoCaja;
use App\Models\Caja;
use App\Models\Categoria;
use App\Models\Costo;
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

class ReportesTest extends TestCase
{
    use RefreshDatabase;

    // ----------------------------------------------------------- Permisos

    public function test_dueno_con_reportes_ver_puede_acceder_al_reporte(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::REPORTES_VER]);

        $this->actingAs($dueno)
            ->get('/reportes')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Reportes/Index'));
    }

    public function test_cajero_sin_reportes_ver_recibe_403(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, [PermisosDisponibles::POS_USAR]);

        $this->actingAs($cajero)->get('/reportes')->assertForbidden();
    }

    public function test_usuario_no_autenticado_es_redirigido_al_login(): void
    {
        $this->get('/reportes')->assertRedirect(route('login'));
    }

    public function test_no_se_puede_acceder_por_otros_verbos(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::REPORTES_VER]);

        $this->actingAs($dueno)->post('/reportes')->assertMethodNotAllowed();
        $this->actingAs($dueno)->put('/reportes')->assertMethodNotAllowed();
        $this->actingAs($dueno)->delete('/reportes')->assertMethodNotAllowed();
    }

    public function test_se_validan_las_fechas(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::REPORTES_VER]);

        $this->actingAs($dueno)
            ->get('/reportes?fecha=13/01/2026')
            ->assertSessionHasErrors('fecha');
    }

    // ------------------------------------------------------- Resumen de ventas

    public function test_el_reporte_muestra_cantidad_y_total_de_ventas_del_dia(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::REPORTES_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000, 500);

        $this->crearVenta($dueno, [['producto_id' => $producto->id, 'cantidad' => 1]]);
        $this->crearVenta($dueno, [['producto_id' => $producto->id, 'cantidad' => 2]]);

        $this->actingAs($dueno)
            ->get('/reportes')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('resumen.ventas.cantidad', 2)
                ->where('resumen.ventas.total', 3000));
    }

    public function test_el_reporte_distingue_la_fecha_consultada(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::REPORTES_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000, 500);

        $v1 = $this->crearVenta($dueno, [['producto_id' => $producto->id, 'cantidad' => 1]]);
        $v1->forceFill(['created_at' => Carbon::parse('2026-01-01 10:00:00')])->save();

        $v2 = $this->crearVenta($dueno, [['producto_id' => $producto->id, 'cantidad' => 1]]);
        $v2->forceFill(['created_at' => Carbon::parse('2026-03-01 10:00:00')])->save();

        $this->actingAs($dueno)
            ->get('/reportes?fecha=2026-01-01')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('resumen.ventas.cantidad', 1)
                ->where('resumen.ventas.total', 1000));

        $this->actingAs($dueno)
            ->get('/reportes?fecha=2026-03-01')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('resumen.ventas.cantidad', 1)
                ->where('resumen.ventas.total', 1000));
    }

    public function test_el_reporte_desglosa_por_medio_de_pago_real(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::REPORTES_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000, 500);

        $this->crearVenta($dueno, [['producto_id' => $producto->id, 'cantidad' => 1]], 'EFECTIVO');
        $this->crearVenta($dueno, [['producto_id' => $producto->id, 'cantidad' => 2]], 'TRANSFERENCIA');

        $this->actingAs($dueno)
            ->get('/reportes')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('resumen.ventas.por_medio.0.medio', 'EFECTIVO')
                ->where('resumen.ventas.por_medio.0.total', 1000)
                ->where('resumen.ventas.por_medio.2.medio', 'TRANSFERENCIA')
                ->where('resumen.ventas.por_medio.2.total', 2000)
                ->where('resumen.ventas.por_medio.1.total', 0));
    }

    // -------------------------------------------------- Ganancias y costos

    public function test_la_ganancia_usa_el_costo_historico_congelado(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::REPORTES_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000, 400);

        $v1 = $this->crearVenta($dueno, [['producto_id' => $producto->id, 'cantidad' => 3]]);
        $v1->forceFill(['created_at' => Carbon::parse('2026-01-01 10:00:00')])->save();

        // Precio 1000, costo 400, cantidad 3 => venta 3000, costo 1200, ganancia 1800.
        $this->actingAs($dueno)
            ->get('/reportes?fecha=2026-01-01')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('resumen.ganancia.costo', 1200)
                ->where('resumen.ganancia.con_costo', 3000)
                ->where('resumen.ganancia.ganancia', 1800)
                ->where('resumen.ganancia.margen', 60));
    }

    public function test_cambiar_el_costo_despues_no_modifica_la_ganancia_historica(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::REPORTES_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000, 400);

        $v1 = $this->crearVenta($dueno, [['producto_id' => $producto->id, 'cantidad' => 2]]);
        $v1->forceFill(['created_at' => Carbon::parse('2026-01-01 10:00:00')])->save();

        // Cambiamos el costo actual a 900; el histórico congelado debe seguir en 400.
        $this->cambiarCosto($producto, 900);

        $this->actingAs($dueno)
            ->get('/reportes?fecha=2026-01-01')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('resumen.ganancia.costo', 800)
                ->where('resumen.ganancia.ganancia', 1200));
    }

    public function test_venta_sin_costo_no_inventa_ganancia(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::REPORTES_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000, null);

        $v1 = $this->crearVenta($dueno, [['producto_id' => $producto->id, 'cantidad' => 5]]);
        $v1->forceFill(['created_at' => Carbon::parse('2026-01-01 10:00:00')])->save();

        $this->actingAs($dueno)
            ->get('/reportes?fecha=2026-01-01')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('resumen.ganancia.con_costo', 0)
                ->where('resumen.ganancia.sin_costo', 5000)
                ->where('resumen.ganancia.costo', 0)
                ->where('resumen.ganancia.ganancia', null)
                ->where('resumen.ganancia.margen', null));
    }

    public function test_mezlcla_sin_costo_con_costo_separa_ambos(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::REPORTES_VER]);
        $conCosto = $this->crearVendible('Papa', 'KILOGRAMO', 1000, 400);
        $sinCosto = $this->crearVendible('Zanahoria', 'KILOGRAMO', 2000, null);

        $v1 = $this->crearVenta($dueno, [
            ['producto_id' => $conCosto->id, 'cantidad' => 2],
            ['producto_id' => $sinCosto->id, 'cantidad' => 1],
        ]);
        $v1->forceFill(['created_at' => Carbon::parse('2026-01-01 10:00:00')])->save();

        $this->actingAs($dueno)
            ->get('/reportes?fecha=2026-01-01')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('resumen.ventas.total', 4000)
                ->where('resumen.ganancia.con_costo', 2000)
                ->where('resumen.ganancia.sin_costo', 2000)
                ->where('resumen.ganancia.costo', 800)
                ->where('resumen.ganancia.ganancia', 1200)
                ->where('resumen.ganancia.margen', 60));
    }

    // ------------------------------------------------------------ Caja

    public function test_el_resumen_de_caja_usa_la_formula_existente(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::REPORTES_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 2000, 800);

        $caja = $this->abrirCaja($dueno, 1000);
        $v = $this->crearVentaEnCaja($dueno, $caja, [['producto_id' => $producto->id, 'cantidad' => 1]], 'EFECTIVO');
        $v->forceFill(['created_at' => Carbon::parse('2026-01-01 10:00:00')])->save();

        app(CajaService::class)->registrarManual($caja, $dueno, TipoMovimientoCaja::INGRESO, 500, 'Cambio de billetes');
        app(CajaService::class)->registrarManual($caja, $dueno, TipoMovimientoCaja::EGRESO, 300, 'Compra de bolsas');

        // monto inicial 1000 + ventas efectivo 2000 + ingresos 500 − egresos 300 = 3200
        $this->actingAs($dueno)
            ->get('/reportes?fecha=2026-01-01')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('resumen.cajas.cajas.0.monto_inicial', 1000)
                ->where('resumen.cajas.cajas.0.ventas_efectivo', 2000)
                ->where('resumen.cajas.cajas.0.ingresos', 500)
                ->where('resumen.cajas.cajas.0.egresos', 300)
                ->where('resumen.cajas.cajas.0.efectivo_esperado', 3200)
                ->where('resumen.cajas.agregado.efectivo_esperado', 3200));
    }

    public function test_el_reporte_considera_solo_las_cajas_con_movimiento_ese_dia(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::REPORTES_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000, 500);

        $cajaA = $this->abrirCaja($dueno, 0);
        $diaA = $this->crearVentaEnCaja($dueno, $cajaA, [['producto_id' => $producto->id, 'cantidad' => 1]]);
        $diaA->forceFill(['created_at' => Carbon::parse('2026-01-01 10:00:00')])->save();
        app(CajaService::class)->cerrar($dueno, $cajaA, 1000);

        $cajaB = $this->abrirCaja($dueno, 0);
        $diaB = $this->crearVentaEnCaja($dueno, $cajaB, [['producto_id' => $producto->id, 'cantidad' => 2]]);
        $diaB->forceFill(['created_at' => Carbon::parse('2026-03-01 10:00:00')])->save();

        $this->assertNotSame($diaA->caja_id, $diaB->caja_id);

        $this->actingAs($dueno)
            ->get('/reportes?fecha=2026-01-01')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('resumen.cajas.agregado.cantidad_cajas', 1)
                ->where('resumen.cajas.agregado.efectivo_esperado', 1000));
    }

    // -------------------------------------------------------- Historico / borde

    public function test_un_dia_sin_ventas_muestra_ceros_y_margen_na(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::REPORTES_VER]);

        $this->actingAs($dueno)
            ->get('/reportes?fecha=2025-05-05')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('resumen.ventas.cantidad', 0)
                ->where('resumen.ventas.total', 0)
                ->where('resumen.ganancia.ganancia', null)
                ->where('resumen.ganancia.margen', null)
                ->where('resumen.cajas.agregado.cantidad_cajas', 0)
                ->has('resumen.mas_vendidos', 0));
    }

    public function test_el_reporte_lista_los_productos_mas_vendidos(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::REPORTES_VER]);
        $papa = $this->crearVendible('Papa', 'KILOGRAMO', 1000, 500);
        $zanahoria = $this->crearVendible('Zanahoria', 'KILOGRAMO', 1000, 500);

        $v = $this->crearVenta($dueno, [
            ['producto_id' => $papa->id, 'cantidad' => 5],
            ['producto_id' => $zanahoria->id, 'cantidad' => 1],
        ]);
        $v->forceFill(['created_at' => Carbon::parse('2026-01-01 10:00:00')])->save();

        $this->actingAs($dueno)
            ->get('/reportes?fecha=2026-01-01')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('resumen.mas_vendidos.0.nombre', 'Papa')
                ->where('resumen.mas_vendidos.0.cantidad', 5)
                ->where('resumen.mas_vendidos.1.nombre', 'Zanahoria'));
    }

    // ------------------------------------------------------- Rango de fechas

    public function test_el_reporte_acepta_un_rango_de_varios_dias(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::REPORTES_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000, 500);

        $v1 = $this->crearVenta($dueno, [['producto_id' => $producto->id, 'cantidad' => 1]]);
        $v1->forceFill(['created_at' => Carbon::parse('2026-01-01 10:00:00')])->save();

        $v2 = $this->crearVenta($dueno, [['producto_id' => $producto->id, 'cantidad' => 2]]);
        $v2->forceFill(['created_at' => Carbon::parse('2026-01-05 10:00:00')])->save();

        $v3 = $this->crearVenta($dueno, [['producto_id' => $producto->id, 'cantidad' => 1]]);
        $v3->forceFill(['created_at' => Carbon::parse('2026-02-01 10:00:00')])->save();

        $this->actingAs($dueno)
            ->get('/reportes?desde=2026-01-01&hasta=2026-01-31')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('resumen.ventas.cantidad', 2)
                ->where('resumen.ventas.total', 3000)
                ->where('resumen.desde', '2026-01-01')
                ->where('resumen.hasta', '2026-01-31'));
    }

    public function test_un_rango_de_un_mes_incluye_el_mes_completo(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::REPORTES_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000, 500);

        $inicio = $this->crearVenta($dueno, [['producto_id' => $producto->id, 'cantidad' => 1]]);
        $inicio->forceFill(['created_at' => Carbon::parse('2026-01-01 00:00:00')])->save();

        $ultimoDia = $this->crearVenta($dueno, [['producto_id' => $producto->id, 'cantidad' => 1]]);
        $ultimoDia->forceFill(['created_at' => Carbon::parse('2026-01-31 23:59:59')])->save();

        $fuera = $this->crearVenta($dueno, [['producto_id' => $producto->id, 'cantidad' => 1]]);
        $fuera->forceFill(['created_at' => Carbon::parse('2026-02-01 00:00:00')])->save();

        $this->actingAs($dueno)
            ->get('/reportes?desde=2026-01-01&hasta=2026-01-31')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('resumen.ventas.cantidad', 2)
                ->where('resumen.ventas.total', 2000)
                ->has('resumen.ventas_por_dia', 31));
    }

    public function test_un_rango_de_un_solo_dia_equivale_a_la_fecha_puntual(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::REPORTES_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000, 500);

        $v = $this->crearVenta($dueno, [['producto_id' => $producto->id, 'cantidad' => 1]]);
        $v->forceFill(['created_at' => Carbon::parse('2026-01-01 10:00:00')])->save();

        $this->actingAs($dueno)
            ->get('/reportes?desde=2026-01-01&hasta=2026-01-01')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('resumen.ventas.cantidad', 1)
                ->where('resumen.ventas.total', 1000)
                ->where('resumen.desde', '2026-01-01')
                ->where('resumen.hasta', '2026-01-01'));
    }

    public function test_un_rango_invertido_es_rechazado(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::REPORTES_VER]);

        $this->actingAs($dueno)
            ->get('/reportes?desde=2026-02-01&hasta=2026-01-15')
            ->assertSessionHasErrors('desde');
    }

    public function test_las_fechas_del_rango_se_validan(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::REPORTES_VER]);

        $this->actingAs($dueno)
            ->get('/reportes?desde=13/01/2026&hasta=2026-01-31')
            ->assertSessionHasErrors('desde');

        $this->actingAs($dueno)
            ->get('/reportes?desde=2026-01-01&hasta=15/01/2026')
            ->assertSessionHasErrors('hasta');
    }

    public function test_sin_rango_el_reporte_usa_el_dia_de_hoy(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::REPORTES_VER]);

        $hoy = now(config('app.timezone'))->format('Y-m-d');

        $this->actingAs($dueno)
            ->get('/reportes')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('resumen.desde', $hoy)
                ->where('resumen.hasta', $hoy));
    }

    // ----------------------------------------------------- Ventas por día

    public function test_ventas_por_dia_incluye_todos_los_dias_y_ceros(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::REPORTES_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000, 500);

        $v1 = $this->crearVenta($dueno, [['producto_id' => $producto->id, 'cantidad' => 1]]);
        $v1->forceFill(['created_at' => Carbon::parse('2026-01-01 10:00:00')])->save();

        $v2 = $this->crearVenta($dueno, [['producto_id' => $producto->id, 'cantidad' => 3]]);
        $v2->forceFill(['created_at' => Carbon::parse('2026-01-03 10:00:00')])->save();

        $this->actingAs($dueno)
            ->get('/reportes?desde=2026-01-01&hasta=2026-01-03')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('resumen.ventas_por_dia', 3)
                ->where('resumen.ventas_por_dia.0.fecha', '2026-01-01')
                ->where('resumen.ventas_por_dia.0.total', 1000)
                ->where('resumen.ventas_por_dia.1.fecha', '2026-01-02')
                ->where('resumen.ventas_por_dia.1.total', 0)
                ->where('resumen.ventas_por_dia.2.fecha', '2026-01-03')
                ->where('resumen.ventas_por_dia.2.total', 3000));
    }

    public function test_ventas_por_dia_suma_solo_las_ventas_del_dia(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::REPORTES_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000, 500);

        $v1 = $this->crearVenta($dueno, [['producto_id' => $producto->id, 'cantidad' => 1]]);
        $v1->forceFill(['created_at' => Carbon::parse('2026-01-01 10:00:00')])->save();

        $v2 = $this->crearVenta($dueno, [['producto_id' => $producto->id, 'cantidad' => 2]]);
        $v2->forceFill(['created_at' => Carbon::parse('2026-01-01 18:00:00')])->save();

        $v3 = $this->crearVenta($dueno, [['producto_id' => $producto->id, 'cantidad' => 1]]);
        $v3->forceFill(['created_at' => Carbon::parse('2026-01-02 10:00:00')])->save();

        $this->actingAs($dueno)
            ->get('/reportes?desde=2026-01-01&hasta=2026-01-02')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('resumen.ventas_por_dia.0.total', 3000)
                ->where('resumen.ventas_por_dia.1.total', 1000));
    }

    public function test_un_rango_sin_ventas_queda_entero_en_cero(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::REPORTES_VER]);

        $this->actingAs($dueno)
            ->get('/reportes?desde=2025-05-05&hasta=2025-05-07')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('resumen.ventas_por_dia', 3)
                ->where('resumen.ventas_por_dia.0.total', 0)
                ->where('resumen.ventas_por_dia.1.total', 0)
                ->where('resumen.ventas_por_dia.2.total', 0));
    }

    public function test_el_desglose_por_medio_respeta_el_mismo_rango(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::REPORTES_VER]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000, 500);

        $vEfectivo = $this->crearVenta($dueno, [['producto_id' => $producto->id, 'cantidad' => 1]], 'EFECTIVO');
        $vEfectivo->forceFill(['created_at' => Carbon::parse('2026-01-01 10:00:00')])->save();

        $vTransferencia = $this->crearVenta($dueno, [['producto_id' => $producto->id, 'cantidad' => 2]], 'TRANSFERENCIA');
        $vTransferencia->forceFill(['created_at' => Carbon::parse('2026-01-02 10:00:00')])->save();

        $vFuera = $this->crearVenta($dueno, [['producto_id' => $producto->id, 'cantidad' => 1]], 'EFECTIVO');
        $vFuera->forceFill(['created_at' => Carbon::parse('2026-02-01 10:00:00')])->save();

        $this->actingAs($dueno)
            ->get('/reportes?desde=2026-01-01&hasta=2026-01-31')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('resumen.ventas.por_medio.0.medio', 'EFECTIVO')
                ->where('resumen.ventas.por_medio.0.total', 1000)
                ->where('resumen.ventas.por_medio.1.total', 0)
                ->where('resumen.ventas.por_medio.2.medio', 'TRANSFERENCIA')
                ->where('resumen.ventas.por_medio.2.total', 2000)
                ->where('resumen.ventas.total', 3000));
    }

    // ------------------------------------------------------------- Helpers

    private function abrirCaja(Usuario $usuario, float $monto): Caja
    {
        return app(CajaService::class)->abrir($usuario, $monto);
    }

    private function crearVentaEnCaja(Usuario $cajero, Caja $caja, array $items, string $medioPago = 'EFECTIVO'): Venta
    {
        $efectivoRecibido = $medioPago === 'EFECTIVO' ? $this->totalDeItems($items) : null;

        return app(VentaService::class)->registrar($cajero, $medioPago, $items, $efectivoRecibido);
    }

    private function crearVenta(Usuario $cajero, array $items, string $medioPago = 'EFECTIVO'): Venta
    {
        $caja = app(CajaService::class)->actualDelUsuario($cajero) ?? $this->abrirCaja($cajero, 0);

        return $this->crearVentaEnCaja($cajero, $caja, $items, $medioPago);
    }

    private function crearVendible(
        string $nombre,
        string $unidad,
        float $monto,
        ?float $costo,
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

        if ($costo !== null) {
            Costo::create([
                'producto_id' => $producto->id,
                'precio' => $costo,
                'vigente' => true,
                'usuario_id' => null,
            ]);
        }

        return $producto;
    }

    private function cambiarCosto(Producto $producto, float $costo): void
    {
        $vigente = Costo::where('producto_id', $producto->id)->where('vigente', true)->first();
        $vigente?->update(['vigente' => false]);

        Costo::create([
            'producto_id' => $producto->id,
            'precio' => $costo,
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
