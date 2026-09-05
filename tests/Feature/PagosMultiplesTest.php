<?php

namespace Tests\Feature;

use App\Enums\AccionAuditoria;
use App\Enums\EstadoPagoVenta;
use App\Enums\TipoMovimientoCaja;
use App\Models\Auditoria;
use App\Models\Caja;
use App\Models\Categoria;
use App\Models\DispositivoImpresion;
use App\Models\MovimientoCaja;
use App\Models\PagoVenta;
use App\Models\Permiso;
use App\Models\Precio;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\CajaService;
use App\Support\Permisos as PermisosDisponibles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PagosMultiplesTest extends TestCase
{
    use RefreshDatabase;

    private int $productoCounter = 0;

    // --------------------------------------------------- Venta definitiva con desglose

    public function test_venta_mixta_persiste_cada_pago_y_crea_un_movimiento_por_medio(): void
    {
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero, 5000);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'pagos' => [
                    ['medio_pago' => 'EFECTIVO', 'monto' => 500],
                    ['medio_pago' => 'TRANSFERENCIA', 'monto' => 1000],
                ],
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
                'efectivo_recibido' => 500,
            ])
            ->assertRedirect(route('pos.index'));

        $venta = Venta::query()->where('usuario_id', $cajero->id)->first();

        // La venta conserva el medio legado (EFECTIVO si hay efectivo) y los totales.
        $this->assertDatabaseHas('ventas', [
            'id' => $venta->id,
            'medio_pago' => 'EFECTIVO',
            'total' => 1500,
            'efectivo_recibido' => 500,
            'vuelto' => 0,
            'estado_pago' => EstadoPagoVenta::PAGADA->value,
        ]);

        // Una fila por medio en pagos_venta.
        $this->assertDatabaseHas('pagos_venta', [
            'venta_id' => $venta->id,
            'medio_pago' => 'EFECTIVO',
            'monto' => 500,
        ]);
        $this->assertDatabaseHas('pagos_venta', [
            'venta_id' => $venta->id,
            'medio_pago' => 'TRANSFERENCIA',
            'monto' => 1000,
        ]);

        // Un movimiento de caja VENTA por cada medio aplicado.
        $this->assertDatabaseHas('movimientos_caja', [
            'venta_id' => $venta->id,
            'tipo' => TipoMovimientoCaja::VENTA->value,
            'medio_pago' => 'EFECTIVO',
            'monto' => 500,
        ]);
        $this->assertDatabaseHas('movimientos_caja', [
            'venta_id' => $venta->id,
            'tipo' => TipoMovimientoCaja::VENTA->value,
            'medio_pago' => 'TRANSFERENCIA',
            'monto' => 1000,
        ]);
        $this->assertSame(2, MovimientoCaja::query()
            ->where('venta_id', $venta->id)
            ->where('tipo', TipoMovimientoCaja::VENTA)
            ->count());

        $this->assertDatabaseHas('tickets', ['venta_id' => $venta->id]);
    }

    public function test_vuelto_en_venta_mixta_se_calcula_sobre_el_efectivo_aplicado(): void
    {
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'pagos' => [
                    ['medio_pago' => 'EFECTIVO', 'monto' => 500],
                    ['medio_pago' => 'TARJETA', 'monto' => 1000],
                ],
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
                // Recibió más que el efectivo aplicado (500): vuelto de 100.
                'efectivo_recibido' => 600,
            ])
            ->assertRedirect(route('pos.index'));

        $venta = Venta::query()->where('usuario_id', $cajero->id)->first();

        $this->assertSame(600.0, (float) $venta->efectivo_recibido);
        $this->assertSame(100.0, (float) $venta->vuelto);
    }

    public function test_venta_mixta_sin_efectivo_no_guarda_recibido_ni_vuelto(): void
    {
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'pagos' => [
                    ['medio_pago' => 'TARJETA', 'monto' => 500],
                    ['medio_pago' => 'TRANSFERENCIA', 'monto' => 1000],
                ],
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
                'efectivo_recibido' => 9999,
            ])
            ->assertRedirect(route('pos.index'));

        $venta = Venta::query()->where('usuario_id', $cajero->id)->first();
        $venta->refresh();

        // Sin efectivo aplicado, recibido y vuelto quedan null (aunque vengan datos).
        $this->assertNull($venta->efectivo_recibido);
        $this->assertNull($venta->vuelto);
        $this->assertSame('TARJETA', $venta->medio_pago->value);
    }

    public function test_venta_mixta_con_efectivo_no_exige_efectivo_recibido(): void
    {
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'pagos' => [
                    ['medio_pago' => 'EFECTIVO', 'monto' => 500],
                    ['medio_pago' => 'TARJETA', 'monto' => 1000],
                ],
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
            ])
            ->assertRedirect(route('pos.index'));

        $venta = Venta::query()->where('usuario_id', $cajero->id)->first();

        // En ventas mixtas el efectivo recibido es opcional: sin recibido no
        // hay efectivo "a favor" del cliente, así que recibido y vuelto quedan null.
        $this->assertSame(EstadoPagoVenta::PAGADA, $venta->estado_pago);
        $this->assertNull($venta->efectivo_recibido);
        $this->assertNull($venta->vuelto);
    }

    public function test_venta_totalmente_en_efectivo_sigue_exigiendo_efectivo_recibido(): void
    {
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'pagos' => [
                    ['medio_pago' => 'EFECTIVO', 'monto' => 1500],
                ],
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
            ])
            ->assertSessionHasErrors('efectivo_recibido');

        $this->assertSame(0, Venta::count());
    }

    public function test_efectivo_recibido_debe_cubrir_el_efectivo_aplicado_no_el_total(): void
    {
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        // El total es 1500 pero solo 800 en efectivo: con 700 recibidos se rechaza.
        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'pagos' => [
                    ['medio_pago' => 'EFECTIVO', 'monto' => 800],
                    ['medio_pago' => 'TARJETA', 'monto' => 700],
                ],
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
                'efectivo_recibido' => 700,
            ])
            ->assertSessionHasErrors('efectivo_recibido');

        $this->assertSame(0, Venta::count());
    }

    public function test_la_suma_de_los_pagos_debe_coincidir_con_el_total(): void
    {
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'pagos' => [
                    ['medio_pago' => 'EFECTIVO', 'monto' => 500],
                    ['medio_pago' => 'TARJETA', 'monto' => 900],
                ],
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
                'efectivo_recibido' => 500,
            ])
            ->assertSessionHasErrors('pagos');

        $this->assertSame(0, Venta::count());
    }

    public function test_un_pago_con_monto_no_positivo_se_rechaza(): void
    {
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'pagos' => [
                    ['medio_pago' => 'EFECTIVO', 'monto' => 0],
                    ['medio_pago' => 'TARJETA', 'monto' => 1500],
                ],
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
                'efectivo_recibido' => 0,
            ])
            ->assertSessionHasErrors('pagos.0.monto');

        $this->assertSame(0, Venta::count());
    }

    public function test_un_medio_no_puede_repetirse_dentro_del_desglose(): void
    {
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'pagos' => [
                    ['medio_pago' => 'EFECTIVO', 'monto' => 750],
                    ['medio_pago' => 'EFECTIVO', 'monto' => 750],
                ],
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
                'efectivo_recibido' => 750,
            ])
            ->assertSessionHasErrors('pagos.1.medio_pago');

        $this->assertSame(0, Venta::count());
    }

    public function test_un_medio_invalido_en_el_desglose_se_rechaza(): void
    {
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'pagos' => [
                    ['medio_pago' => 'CHEQUE', 'monto' => 1500],
                ],
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
            ])
            ->assertSessionHasErrors('pagos.0.medio_pago');

        $this->assertSame(0, Venta::count());
    }

    // --------------------------------------------------- Compatibilidad con flujo legado

    public function test_el_flujo_legado_de_medio_unico_sigue_creando_pagos_equivalentes(): void
    {
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'medio_pago' => 'TRANSFERENCIA',
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
            ])
            ->assertRedirect(route('pos.index'));

        $venta = Venta::query()->where('usuario_id', $cajero->id)->first();

        // El medio único equivale a un solo pago por el total.
        $this->assertDatabaseHas('pagos_venta', [
            'venta_id' => $venta->id,
            'medio_pago' => 'TRANSFERENCIA',
            'monto' => 1500,
        ]);
        $this->assertSame(1, PagoVenta::where('venta_id', $venta->id)->count());
    }

    public function test_efectivo_legado_sigue_guardando_recibido_y_vuelto(): void
    {
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'medio_pago' => 'EFECTIVO',
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
                'efectivo_recibido' => 2000,
            ])
            ->assertRedirect(route('pos.index'));

        $venta = Venta::query()->where('usuario_id', $cajero->id)->first();

        $this->assertSame(2000.0, (float) $venta->efectivo_recibido);
        $this->assertSame(500.0, (float) $venta->vuelto);
        $this->assertDatabaseHas('pagos_venta', [
            'venta_id' => $venta->id,
            'medio_pago' => 'EFECTIVO',
            'monto' => 1500,
        ]);
    }

    public function test_una_venta_historica_sin_pagos_cae_al_medio_original(): void
    {
        // Simula una venta creada antes de la tabla pagos_venta.
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $venta = Venta::forceCreate([
            'usuario_id' => $cajero->id,
            'caja_id' => $this->abrirCaja($cajero)->id,
            'medio_pago' => 'EFECTIVO',
            'total' => 2400,
            'efectivo_recibido' => 3000,
            'vuelto' => 600,
            'estado_pago' => EstadoPagoVenta::PAGADA->value,
        ]);

        $this->assertSame(0, $venta->pagos()->count());
        $this->assertSame([
            'medio_pago' => 'EFECTIVO',
            'etiqueta' => 'Efectivo',
            'monto' => '2400.00',
        ], $venta->pagosNormalizados()[0]);
    }

    // --------------------------------------------------- Ventas pendientes con desglose

    public function test_pendiente_mixta_guarda_pagos_sin_contabilizar_la_venta(): void
    {
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas/pendientes', [
                'pagos' => [
                    ['medio_pago' => 'EFECTIVO', 'monto' => 500],
                    ['medio_pago' => 'TRANSFERENCIA', 'monto' => 1000],
                ],
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
                'efectivo_recibido' => 500,
            ])
            ->assertRedirect(route('pos.index'));

        $venta = Venta::query()->where('usuario_id', $cajero->id)->first();

        $this->assertSame(EstadoPagoVenta::PENDIENTE_PAGO, $venta->estado_pago);
        $this->assertSame('EFECTIVO', $venta->medio_pago->value);
        $this->assertSame(500.0, (float) $venta->efectivo_recibido);
        $this->assertSame(0.0, (float) $venta->vuelto);
        $this->assertSame(2, PagoVenta::where('venta_id', $venta->id)->count());

        // Pendiente: todavía no hay movimiento, ticket ni auditoría de venta.
        $this->assertDatabaseMissing('movimientos_caja', ['venta_id' => $venta->id]);
        $this->assertDatabaseMissing('tickets', ['venta_id' => $venta->id]);
        $this->assertDatabaseMissing('auditoria', [
            'accion' => AccionAuditoria::VENTA_REALIZADA->value,
            'entidad_id' => $venta->id,
        ]);
    }

    public function test_pendiente_totalmente_en_efectivo_se_rechaza(): void
    {
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas/pendientes', [
                'pagos' => [
                    ['medio_pago' => 'EFECTIVO', 'monto' => 1500],
                ],
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
                'efectivo_recibido' => 1500,
            ])
            ->assertSessionHasErrors('pagos');

        $this->assertSame(0, Venta::count());
    }

    public function test_pendiente_legado_exige_transferencia_o_tarjeta(): void
    {
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas/pendientes', [
                'medio_pago' => 'EFECTIVO',
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
            ])
            ->assertSessionHasErrors('medio_pago');

        $this->assertSame(0, Venta::count());
    }

    public function test_pendiente_legado_con_transferencia_sigue_funcionando(): void
    {
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas/pendientes', [
                'medio_pago' => 'TRANSFERENCIA',
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
            ])
            ->assertRedirect(route('pos.index'));

        $venta = Venta::query()->where('usuario_id', $cajero->id)->first();

        $this->assertSame(EstadoPagoVenta::PENDIENTE_PAGO, $venta->estado_pago);
        $this->assertDatabaseHas('pagos_venta', [
            'venta_id' => $venta->id,
            'medio_pago' => 'TRANSFERENCIA',
            'monto' => 1500,
        ]);
    }

    public function test_confirmar_pendiente_mixta_crea_movimientos_por_medio_ticket_y_auditoria(): void
    {
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $caja = $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas/pendientes', [
                'pagos' => [
                    ['medio_pago' => 'EFECTIVO', 'monto' => 500],
                    ['medio_pago' => 'TARJETA', 'monto' => 1000],
                ],
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
                'efectivo_recibido' => 500,
            ]);

        $venta = Venta::query()->where('usuario_id', $cajero->id)->first();

        $this->actingAs($cajero)
            ->post(route('pos.ventas.confirmar', $venta))
            ->assertRedirect(route('pos.index'))
            ->assertSessionHas('success');

        $venta->refresh();

        $this->assertSame(EstadoPagoVenta::PAGADA, $venta->estado_pago);

        // Un movimiento por cada medio.
        $this->assertDatabaseHas('movimientos_caja', [
            'caja_id' => $caja->id,
            'venta_id' => $venta->id,
            'tipo' => TipoMovimientoCaja::VENTA->value,
            'medio_pago' => 'EFECTIVO',
            'monto' => 500,
        ]);
        $this->assertDatabaseHas('movimientos_caja', [
            'caja_id' => $caja->id,
            'venta_id' => $venta->id,
            'tipo' => TipoMovimientoCaja::VENTA->value,
            'medio_pago' => 'TARJETA',
            'monto' => 1000,
        ]);

        // El ticket final desglosa los pagos aplicados.
        $ticket = $venta->ticket;
        $this->assertNotNull($ticket);
        $contenido = json_decode($ticket->contenido, true);
        $this->assertSame([
            ['medio_pago' => 'EFECTIVO', 'etiqueta' => 'Efectivo', 'monto' => '500.00'],
            ['medio_pago' => 'TARJETA', 'etiqueta' => 'Tarjeta', 'monto' => '1000.00'],
        ], $contenido['pagos']);

        $this->assertDatabaseHas('trabajos_impresion', [
            'ticket_id' => $ticket->id,
            'estado' => 'PENDIENTE',
        ]);

        // Una única auditoría de venta, con el desglose.
        $registro = Auditoria::query()
            ->where('accion', AccionAuditoria::VENTA_REALIZADA->value)
            ->where('entidad_id', $venta->id)
            ->first();

        $this->assertNotNull($registro);
        $this->assertSame('EFECTIVO', $registro->datos_nuevos['medio_pago']);
        $this->assertSame('EFECTIVO', $registro->datos_nuevos['pagos'][0]['medio_pago']);
        $this->assertSame('TARJETA', $registro->datos_nuevos['pagos'][1]['medio_pago']);
        $this->assertSame(2, count($registro->datos_nuevos['pagos']));
    }

    public function test_cancelar_pendiente_elimina_también_sus_pagos(): void
    {
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas/pendientes', [
                'pagos' => [
                    ['medio_pago' => 'EFECTIVO', 'monto' => 500],
                    ['medio_pago' => 'TRANSFERENCIA', 'monto' => 1000],
                ],
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
                'efectivo_recibido' => 500,
            ]);

        $venta = Venta::query()->where('usuario_id', $cajero->id)->first();

        $this->actingAs($cajero)
            ->post(route('pos.ventas.cancelar', $venta))
            ->assertRedirect(route('pos.index'));

        $this->assertDatabaseMissing('ventas', ['id' => $venta->id]);
        $this->assertDatabaseMissing('detalles_venta', ['venta_id' => $venta->id]);
        $this->assertDatabaseMissing('pagos_venta', ['venta_id' => $venta->id]);
    }

    // --------------------------------------------------- POS: pendientes con desglose

    public function test_el_pos_expone_las_pendientes_con_su_desglose(): void
    {
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas/pendientes', [
                'pagos' => [
                    ['medio_pago' => 'EFECTIVO', 'monto' => 500],
                    ['medio_pago' => 'TRANSFERENCIA', 'monto' => 1000],
                ],
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
                'efectivo_recibido' => 500,
            ]);

        $this->actingAs($cajero)
            ->get('/pos')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Pos/Index')
                ->has('ventas_pendientes', 1)
                ->where('ventas_pendientes.0.pagos.0.medio_pago', 'EFECTIVO')
                ->where('ventas_pendientes.0.pagos.0.monto', '500.00')
                ->where('ventas_pendientes.0.pagos.1.medio_pago', 'TRANSFERENCIA')
                ->where('ventas_pendientes.0.pagos.1.monto', '1000.00'));
    }

    // --------------------------------------------------- Historial y detalle

    public function test_el_historial_filtra_por_medio_en_ventas_mixtas(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $mixta = $this->crearVentaDefinitiva($cajero, [
            ['medio_pago' => 'EFECTIVO', 'monto' => 500],
            ['medio_pago' => 'TARJETA', 'monto' => 1000],
        ]);
        $transferencia = $this->crearVentaDefinitiva($cajero, [
            ['medio_pago' => 'TRANSFERENCIA', 'monto' => 1500],
        ]);

        $this->actingAs($cajero)
            ->get('/ventas?medio_pago=TARJETA')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('ventas.data', 1)
                ->where('ventas.data.0.id', $mixta->id));

        $this->actingAs($cajero)
            ->get('/ventas?medio_pago=EFECTIVO')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('ventas.data', 1)
                ->where('ventas.data.0.id', $mixta->id));

        $this->actingAs($cajero)
            ->get('/ventas?medio_pago=TRANSFERENCIA')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('ventas.data', 1)
                ->where('ventas.data.0.id', $transferencia->id));
    }

    public function test_el_listado_expone_el_desglose_de_pagos(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $mixta = $this->crearVentaDefinitiva($cajero, [
            ['medio_pago' => 'EFECTIVO', 'monto' => 500],
            ['medio_pago' => 'TARJETA', 'monto' => 1000],
        ]);

        $this->actingAs($cajero)
            ->get('/ventas')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('ventas.data.0.id', $mixta->id)
                ->where('ventas.data.0.pagos.0.medio_pago', 'EFECTIVO')
                ->where('ventas.data.0.pagos.0.monto', '500.00')
                ->where('ventas.data.0.pagos.1.medio_pago', 'TARJETA')
                ->where('ventas.data.0.pagos.1.monto', '1000.00'));
    }

    public function test_el_detalle_expone_el_desglose_de_pagos(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $mixta = $this->crearVentaDefinitiva($cajero, [
            ['medio_pago' => 'EFECTIVO', 'monto' => 500],
            ['medio_pago' => 'TARJETA', 'monto' => 1000],
        ]);

        $this->actingAs($cajero)
            ->get("/ventas/{$mixta->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Ventas/Show')
                ->where('venta.id', $mixta->id)
                ->has('venta.pagos', 2)
                ->where('venta.pagos.0.medio_pago', 'EFECTIVO')
                ->where('venta.pagos.1.medio_pago', 'TARJETA'));
    }

    // --------------------------------------------------- Reportes y caja

    public function test_el_reporte_desglosa_por_medio_real_sin_duplicar_la_venta(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::REPORTES_VER]);
        $this->abrirCaja($dueno);

        $mixta = $this->crearVentaDefinitiva($dueno, [
            ['medio_pago' => 'EFECTIVO', 'monto' => 500],
            ['medio_pago' => 'TARJETA', 'monto' => 1000],
        ]);
        $pura = $this->crearVentaDefinitiva($dueno, [
            ['medio_pago' => 'EFECTIVO', 'monto' => 2500],
        ]);

        $this->actingAs($dueno)
            ->get('/reportes')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('resumen.ventas.cantidad', 2)
                ->where('resumen.ventas.total', 4000)
                ->where('resumen.ventas.por_medio.0.medio', 'EFECTIVO')
                ->where('resumen.ventas.por_medio.0.cantidad', 2)
                ->where('resumen.ventas.por_medio.0.total', 3000)
                ->where('resumen.ventas.por_medio.1.medio', 'TARJETA')
                ->where('resumen.ventas.por_medio.1.cantidad', 1)
                ->where('resumen.ventas.por_medio.1.total', 1000)
                ->where('resumen.ventas.por_medio.2.medio', 'TRANSFERENCIA')
                ->where('resumen.ventas.por_medio.2.total', 0));
    }

    public function test_el_resumen_de_caja_desglosa_las_ventas_por_medio(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $caja = $this->abrirCaja($cajero, 1000);

        $this->crearVentaDefinitiva($cajero, [
            ['medio_pago' => 'EFECTIVO', 'monto' => 500],
            ['medio_pago' => 'TARJETA', 'monto' => 1000],
        ]);

        $resumen = app(CajaService::class)->resumen($caja);

        $this->assertSame(1500.0, $resumen['total_ventas']);
        $this->assertSame(1, $resumen['cantidad_ventas']);
        $this->assertSame(500.0, $resumen['ventas_efectivo']);
        $this->assertSame(1000.0, $resumen['ventas_tarjeta']);
        $this->assertSame(0.0, $resumen['ventas_transferencia']);

        // Fórmula de caja: inicial 1000 + efectivo 500 = 1500 esperado.
        $this->assertSame(1500.0, $resumen['efectivo_esperado']);
    }

    public function test_el_reporte_de_caja_expone_el_desglose_por_medio_sin_duplicar_la_mixta(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, [PermisosDisponibles::REPORTES_VER]);
        $this->abrirCaja($dueno, 2000);

        $this->crearVentaDefinitiva($dueno, [
            ['medio_pago' => 'EFECTIVO', 'monto' => 2000],
            ['medio_pago' => 'TRANSFERENCIA', 'monto' => 3200],
        ]);
        $this->crearVentaDefinitiva($dueno, [
            ['medio_pago' => 'TARJETA', 'monto' => 1000],
        ]);

        $this->actingAs($dueno)
            ->get('/reportes')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('resumen.cajas.cajas.0.total_ventas', 6200)
                ->where('resumen.cajas.cajas.0.cantidad_ventas', 2)
                ->where('resumen.cajas.cajas.0.ventas_efectivo', 2000)
                ->where('resumen.cajas.cajas.0.ventas_tarjeta', 1000)
                ->where('resumen.cajas.cajas.0.ventas_transferencia', 3200)
                ->where('resumen.cajas.cajas.0.otros', 0)
                // Fórmula de caja: inicial 2000 + solo efectivo 2000 = 4000 esperado.
                ->where('resumen.cajas.cajas.0.efectivo_esperado', 4000)
                ->where('resumen.cajas.agregado.efectivo_esperado', 4000));
    }

    // --------------------------------------------------- Tickets

    public function test_el_snapshot_del_ticket_desglosa_los_pagos_y_el_pdf_lo_renderiza(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $venta = $this->crearVentaDefinitiva($cajero, [
            ['medio_pago' => 'EFECTIVO', 'monto' => 500],
            ['medio_pago' => 'TARJETA', 'monto' => 1000],
        ]);

        $contenido = json_decode($venta->ticket->contenido, true);

        $this->assertSame([
            ['medio_pago' => 'EFECTIVO', 'etiqueta' => 'Efectivo', 'monto' => '500.00'],
            ['medio_pago' => 'TARJETA', 'etiqueta' => 'Tarjeta', 'monto' => '1000.00'],
        ], $contenido['pagos']);

        $respuesta = $this->actingAs($cajero)
            ->get(route('ventas.ticket-pdf', $venta))
            ->assertOk()
            ->assertDownload()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $respuesta->getContent());
    }

    public function test_el_snapshot_de_medio_unico_no_incluye_desglose_en_el_ticket(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $venta = $this->crearVentaDefinitiva($cajero, [
            ['medio_pago' => 'TRANSFERENCIA', 'monto' => 1500],
        ]);

        $contenido = json_decode($venta->ticket->contenido, true);

        $this->assertSame('Transferencia', $contenido['medio_pago']);
        $this->assertArrayHasKey('pagos', $contenido);
        $this->assertSame(1, count($contenido['pagos']));
    }

    public function test_el_snapshot_desglosado_llega_al_bridge_para_imprimir(): void
    {
        $dispositivo = DispositivoImpresion::create([
            'nombre' => 'bridge-001',
            'token_hash' => Hash::make('token-secreto'),
            'activo' => true,
        ]);

        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);
        $this->crearVentaDefinitiva($cajero, [
            ['medio_pago' => 'EFECTIVO', 'monto' => 500],
            ['medio_pago' => 'TARJETA', 'monto' => 1000],
        ]);

        $respuesta = $this->withToken('token-secreto')
            ->getJson('/api/bridge/impresiones/pendientes');

        $respuesta->assertOk();

        $contenido = json_decode($respuesta->json('trabajo.contenido'), true);

        $this->assertSame([
            ['medio_pago' => 'EFECTIVO', 'etiqueta' => 'Efectivo', 'monto' => '500.00'],
            ['medio_pago' => 'TARJETA', 'etiqueta' => 'Tarjeta', 'monto' => '1000.00'],
        ], $contenido['pagos']);
    }

    // --------------------------------------------------- Límites y formatos de montos

    public function test_pago_valido_sin_decimales_se_acepta(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $producto = $this->crearVendible('Papa 2000', 'KILOGRAMO', 5200);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'pagos' => [
                    ['medio_pago' => 'EFECTIVO', 'monto' => 2000],
                    ['medio_pago' => 'TRANSFERENCIA', 'monto' => 3200],
                ],
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
            ])
            ->assertRedirect(route('pos.index'));

        $venta = Venta::query()->where('usuario_id', $cajero->id)->first();

        $this->assertSame(5200.0, (float) $venta->total);
        $this->assertSame(2, PagoVenta::where('venta_id', $venta->id)->count());
    }

    public function test_pago_valido_con_dos_decimales_se_acepta(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $producto = $this->crearVendible('Papa 200050', 'KILOGRAMO', 5200);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'pagos' => [
                    ['medio_pago' => 'EFECTIVO', 'monto' => 2000.50],
                    ['medio_pago' => 'TARJETA', 'monto' => 3199.50],
                ],
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
            ])
            ->assertRedirect(route('pos.index'));

        $venta = Venta::query()->where('usuario_id', $cajero->id)->first();

        $this->assertSame(5200.0, (float) $venta->total);
        $this->assertDatabaseHas('pagos_venta', [
            'venta_id' => $venta->id,
            'medio_pago' => 'EFECTIVO',
            'monto' => 2000.5,
        ]);
        $this->assertDatabaseHas('pagos_venta', [
            'venta_id' => $venta->id,
            'medio_pago' => 'TARJETA',
            'monto' => 3199.5,
        ]);
    }

    public function test_pago_con_mas_de_dos_decimales_se_rechaza(): void
    {
        $producto = $this->crearVendible('Papa 2000999', 'KILOGRAMO', 1500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'pagos' => [
                    ['medio_pago' => 'EFECTIVO', 'monto' => 2000.999],
                    ['medio_pago' => 'TARJETA', 'monto' => 3199.001],
                ],
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
            ])
            ->assertSessionHasErrors('pagos.0.monto');

        $this->assertSame(0, Venta::count());
    }

    public function test_pago_negativo_se_rechaza(): void
    {
        $producto = $this->crearVendible('Papa negativa', 'KILOGRAMO', 1500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'pagos' => [
                    ['medio_pago' => 'EFECTIVO', 'monto' => -2000],
                    ['medio_pago' => 'TARJETA', 'monto' => 3500],
                ],
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
            ])
            ->assertSessionHasErrors('pagos.0.monto');

        $this->assertSame(0, Venta::count());
    }

    public function test_la_suma_de_los_pagos_no_puede_superar_el_total(): void
    {
        $producto = $this->crearVendible('Papa excede', 'KILOGRAMO', 1500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'pagos' => [
                    ['medio_pago' => 'EFECTIVO', 'monto' => 1500],
                    ['medio_pago' => 'TARJETA', 'monto' => 500],
                ],
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
            ])
            ->assertSessionHasErrors('pagos');

        $this->assertSame(0, Venta::count());
    }

    public function test_pago_fuera_del_rango_decimal_12_2_se_rechaza(): void
    {
        $producto = $this->crearVendible('Papa rango', 'KILOGRAMO', 1500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'pagos' => [
                    ['medio_pago' => 'TRANSFERENCIA', 'monto' => 10000000000],
                ],
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
            ])
            ->assertSessionHasErrors('pagos.0.monto');

        $this->assertSame(0, Venta::count());
    }

    public function test_venta_100_efectivo_con_efectivo_recibido_exacto_da_vuelto_cero(): void
    {
        $producto = $this->crearVendible('Papa exacto', 'KILOGRAMO', 5200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'pagos' => [
                    ['medio_pago' => 'EFECTIVO', 'monto' => 5200],
                ],
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
                'efectivo_recibido' => 5200,
            ])
            ->assertRedirect(route('pos.index'));

        $venta = Venta::query()->where('usuario_id', $cajero->id)->first();

        $this->assertSame(5200.0, (float) $venta->efectivo_recibido);
        $this->assertSame(0.0, (float) $venta->vuelto);
    }

    public function test_venta_100_efectivo_con_efectivo_recibido_mayor_calcula_vuelto(): void
    {
        $producto = $this->crearVendible('Papa vuelto', 'KILOGRAMO', 5200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'pagos' => [
                    ['medio_pago' => 'EFECTIVO', 'monto' => 5200],
                ],
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
                'efectivo_recibido' => 6000,
            ])
            ->assertRedirect(route('pos.index'));

        $venta = Venta::query()->where('usuario_id', $cajero->id)->first();

        $this->assertSame(6000.0, (float) $venta->efectivo_recibido);
        $this->assertSame(800.0, (float) $venta->vuelto);
    }

    public function test_efectivo_recibido_menor_al_total_en_venta_100_efectivo_se_rechaza(): void
    {
        $producto = $this->crearVendible('Papa menor', 'KILOGRAMO', 5200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'pagos' => [
                    ['medio_pago' => 'EFECTIVO', 'monto' => 5200],
                ],
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
                'efectivo_recibido' => 5000,
            ])
            ->assertSessionHasErrors('efectivo_recibido');

        $this->assertSame(0, Venta::count());
    }

    public function test_efectivo_recibido_con_mas_de_dos_decimales_se_rechaza(): void
    {
        $producto = $this->crearVendible('Papa decim', 'KILOGRAMO', 5200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'pagos' => [
                    ['medio_pago' => 'EFECTIVO', 'monto' => 5200],
                ],
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
                'efectivo_recibido' => 5200.123,
            ])
            ->assertSessionHasErrors('efectivo_recibido');

        $this->assertSame(0, Venta::count());
    }

    public function test_efectivo_recibido_fuera_del_rango_decimal_12_2_se_rechaza(): void
    {
        $producto = $this->crearVendible('Papa recibi', 'KILOGRAMO', 5200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'pagos' => [
                    ['medio_pago' => 'EFECTIVO', 'monto' => 5200],
                ],
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
                'efectivo_recibido' => 10000000000,
            ])
            ->assertSessionHasErrors('efectivo_recibido');

        $this->assertSame(0, Venta::count());
    }

    // --------------------------------------------------- Helpers

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

    private function abrirCaja(Usuario $cajero, float $montoInicial = 0.0): Caja
    {
        return app(CajaService::class)->abrir($cajero, $montoInicial);
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

    /**
     * Registra una venta definitiva desglosada por HTTP como el usuario actual.
     *
     * @param  list<array{medio_pago: string, monto: float}>  $pagos
     */
    private function crearVentaDefinitiva(Usuario $usuario, array $pagos): Venta
    {
        $total = (float) array_sum(array_column($pagos, 'monto'));

        // Producto cuyo precio unitario coincide con el total: los pagos
        // siempre cuadran de forma exacta con una sola unidad vendida. Se usa
        // un nombre único para poder registrar varias ventas en un mismo test.
        $this->productoCounter++;
        $producto = $this->crearVendible('Papa '.$this->productoCounter, 'KILOGRAMO', $total);

        $datos = [
            'pagos' => $pagos,
            'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ];

        $efectivo = null;

        foreach ($pagos as $pago) {
            if ($pago['medio_pago'] === 'EFECTIVO') {
                $efectivo = $pago['monto'];
            }
        }

        if ($efectivo !== null) {
            $datos['efectivo_recibido'] = $efectivo;
        }

        $this->actingAs($usuario)
            ->post('/pos/ventas', $datos)
            ->assertRedirect(route('pos.index'));

        return Venta::query()->where('usuario_id', $usuario->id)->latest('id')->first();
    }
}
