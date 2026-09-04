<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Categoria;
use App\Models\DetalleVenta;
use App\Models\Permiso;
use App\Models\Precio;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Ticket;
use App\Models\TrabajoImpresion;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\CajaService;
use App\Services\VentaService;
use App\Support\Permisos as PermisosDisponibles;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------ Venta + ticket

    public function test_una_venta_genera_un_ticket_con_su_venta(): void
    {
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        $venta = $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 2]]);

        $this->assertSame(1, Ticket::count());

        $ticket = Ticket::first();

        $this->assertSame($venta->id, $ticket->venta_id);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $ticket->numero);
        $this->assertSame(str_pad((string) $venta->id, 6, '0', STR_PAD_LEFT), $ticket->numero);
        $this->assertNull($ticket->impreso_en);
    }

    public function test_el_ticket_conserva_cantidades_y_precios_historicos(): void
    {
        $papa = $this->crearVendible('Papa', 'KILOGRAMO', 1500);
        $gaseosa = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        $this->vender($cajero, [
            ['producto_id' => $papa->id, 'cantidad' => 1.5],
            ['producto_id' => $gaseosa->id, 'cantidad' => 3],
        ]);

        $contenido = json_decode(Ticket::first()->contenido, true);

        $this->assertCount(2, $contenido['detalles']);

        $this->assertSame('Papa', $contenido['detalles'][0]['nombre']);
        $this->assertSame('KILOGRAMO', $contenido['detalles'][0]['unidad_medida']);
        $this->assertSame('1.500', $contenido['detalles'][0]['cantidad']);
        $this->assertSame('1500.00', $contenido['detalles'][0]['precio_unitario']);
        $this->assertSame('2250.00', $contenido['detalles'][0]['subtotal']);

        $this->assertSame('Gaseosa', $contenido['detalles'][1]['nombre']);
        $this->assertSame('UNIDAD', $contenido['detalles'][1]['unidad_medida']);
        $this->assertSame('3.000', $contenido['detalles'][1]['cantidad']);
        $this->assertSame('1200.00', $contenido['detalles'][1]['precio_unitario']);
        $this->assertSame('3600.00', $contenido['detalles'][1]['subtotal']);

        // El total del ticket coincide con la venta.
        $venta = Venta::first();
        $this->assertSame((string) $venta->total, $contenido['total']);
        $this->assertSame('5850.00', $contenido['total']);
    }

    public function test_el_ticket_incluye_fecha_usuario_medio_de_pago_y_datos_del_comercio(): void
    {
        $producto = $this->crearVendible('Banana', 'KILOGRAMO', 2500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]], 'TRANSFERENCIA');

        $contenido = json_decode(Ticket::first()->contenido, true);

        $this->assertSame($cajero->name, $contenido['usuario']);
        $this->assertSame('Transferencia', $contenido['medio_pago']);
        $this->assertNotEmpty($contenido['fecha']);
        $this->assertSame(config('comercio.nombre'), $contenido['comercio']['nombre']);
        $this->assertSame(config('comercio.leyenda'), $contenido['comercio']['leyenda']);
    }

    public function test_el_ticket_en_efectivo_guarda_recibido_y_vuelto_en_el_snapshot(): void
    {
        $producto = $this->crearVendible('Banana', 'KILOGRAMO', 2500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        $items = [['producto_id' => $producto->id, 'cantidad' => 2]];
        $venta = app(VentaService::class)->registrar($cajero, 'EFECTIVO', $items, 10000);

        $contenido = json_decode(Ticket::where('venta_id', $venta->id)->first()->contenido, true);

        $this->assertSame('10000.00', $contenido['efectivo_recibido']);
        $this->assertSame('5000.00', $contenido['vuelto']);
        $this->assertSame('5000.00', $contenido['total']);
    }

    public function test_el_ticket_por_transferencia_o_tarjeta_no_incluye_recibido_ni_vuelto(): void
    {
        $producto = $this->crearVendible('Banana', 'KILOGRAMO', 2500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]], 'TRANSFERENCIA');

        $contenido = json_decode(Ticket::first()->contenido, true);

        $this->assertArrayNotHasKey('efectivo_recibido', $contenido);
        $this->assertArrayNotHasKey('vuelto', $contenido);
    }

    // ------------------------------------------------------------------ Trabajo de impresión

    public function test_una_venta_genera_exactamente_un_trabajo_de_impresion_pendiente(): void
    {
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        $this->assertSame(1, TrabajoImpresion::count());

        $trabajo = TrabajoImpresion::first();

        $this->assertSame(Ticket::first()->id, $trabajo->ticket_id);
        $this->assertSame('PENDIENTE', $trabajo->estado->value);
        $this->assertSame(0, $trabajo->cantidad_intentos);
        $this->assertNull($trabajo->ultimo_error);
        $this->assertNull($trabajo->impreso_en);
    }

    public function test_dos_ventas_generan_dos_tickets_y_dos_trabajos_sin_duplicados(): void
    {
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);
        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 2]], 'TARJETA');

        $this->assertSame(2, Ticket::count());
        $this->assertSame(2, TrabajoImpresion::count());

        foreach (Ticket::all() as $ticket) {
            $this->assertSame(1, TrabajoImpresion::where('ticket_id', $ticket->id)->count());
        }
    }

    public function test_no_puede_crearse_un_segundo_ticket_para_la_misma_venta(): void
    {
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        $venta = Venta::first();

        $this->expectException(QueryException::class);

        Ticket::create([
            'venta_id' => $venta->id,
            'numero' => '999999',
            'contenido' => null,
        ]);
    }

    public function test_no_puede_crearse_un_segundo_trabajo_para_el_mismo_ticket(): void
    {
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        $ticket = Ticket::first();

        $this->expectException(QueryException::class);

        TrabajoImpresion::create(['ticket_id' => $ticket->id]);
    }

    public function test_si_falla_la_creacion_del_ticket_se_hace_rollback_de_la_venta(): void
    {
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->abrirCaja($cajero);

        Ticket::creating(function () {
            throw new \Exception('fallo simulado en la emisión del ticket');
        });

        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'medio_pago' => 'EFECTIVO',
                'items' => [['producto_id' => $producto->id, 'cantidad' => 1]],
                'efectivo_recibido' => 1200,
            ])
            ->assertServerError();

        $this->assertSame(0, Venta::count());
        $this->assertSame(0, DetalleVenta::count());
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, TrabajoImpresion::count());
    }

    // ------------------------------------------------------------------ Integridad histórica

    public function test_cambiar_el_precio_del_producto_no_modifica_el_ticket_historico(): void
    {
        $producto = $this->crearVendible('Banana', 'KILOGRAMO', 2500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 2]]);

        $contenidoAntes = Ticket::first()->contenido;

        $this->assertDatabaseHas('detalles_venta', [
            'venta_id' => Venta::first()->id,
            'precio_unitario' => 2500,
            'subtotal' => 5000,
        ]);

        $this->cambiarPrecio($producto, 3000);

        $this->assertDatabaseHas('detalles_venta', [
            'venta_id' => Venta::first()->id,
            'precio_unitario' => 2500,
            'subtotal' => 5000,
        ]);

        $this->assertSame($contenidoAntes, Ticket::first()->contenido);
        $this->assertSame('2500.00', json_decode(Ticket::first()->contenido, true)['detalles'][0]['precio_unitario']);
    }

    public function test_cambiar_datos_actuales_del_producto_no_modifica_el_ticket_historico(): void
    {
        $producto = $this->crearVendible('Banana', 'KILOGRAMO', 2500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        $ticket = Ticket::first();
        $contenidoAntes = $ticket->contenido;

        $producto->update(['nombre' => 'Banana Cavendish', 'activo' => false]);

        $this->assertSame($contenidoAntes, Ticket::first()->contenido);

        $contenido = json_decode(Ticket::first()->contenido, true);
        $this->assertSame('Banana', $contenido['detalles'][0]['nombre']);
        $this->assertSame('KILOGRAMO', $contenido['detalles'][0]['unidad_medida']);
    }

    // ------------------------------------------------------------------ Permisos

    public function test_el_seeder_no_otorga_permisos_de_impresion_al_cajero(): void
    {
        $this->seed();

        $cajero = Usuario::where('email', env('SEED_CAJERO_EMAIL', 'cajero@verduleria.local'))->first();
        $dueno = Usuario::where('email', env('SEED_ADMIN_EMAIL', 'admin@verduleria.local'))->first();

        $this->assertTrue($cajero->tienePermiso(PermisosDisponibles::POS_USAR));
        $this->assertTrue($cajero->tienePermiso(PermisosDisponibles::VENTAS_REALIZAR));
        $this->assertFalse($cajero->tienePermiso(PermisosDisponibles::IMPRESIONES_GESTIONAR));
        $this->assertTrue($dueno->tienePermiso(PermisosDisponibles::IMPRESIONES_GESTIONAR));
    }

    public function test_cajero_puede_vender_y_genera_ticket_sin_obtener_permisos_administrativos(): void
    {
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        $venta = $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        $this->assertNotNull($venta->ticket);
        $this->assertTrue($cajero->can(PermisosDisponibles::POS_USAR));
        $this->assertFalse($cajero->can(PermisosDisponibles::PRODUCTOS_CREAR));
        $this->assertFalse($cajero->can(PermisosDisponibles::IMPRESIONES_GESTIONAR));
    }

    // ------------------------------------------------------------------ Endpoints de impresión

    public function test_los_endpoints_de_impresion_requieren_autenticacion(): void
    {
        $this->get('/api/impresiones/pendientes')->assertRedirect(route('login'));
        $this->put('/api/impresiones/1/procesando')->assertRedirect(route('login'));
    }

    public function test_el_cajero_no_puede_consumir_la_cola_de_impresion(): void
    {
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        $trabajo = TrabajoImpresion::first();

        $this->actingAs($cajero)->get('/api/impresiones/pendientes')->assertForbidden();
        $this->actingAs($cajero)->put("/api/impresiones/{$trabajo->id}/procesando")->assertForbidden();
        $this->actingAs($cajero)->put("/api/impresiones/{$trabajo->id}/impreso")->assertForbidden();
        $this->actingAs($cajero)->put("/api/impresiones/{$trabajo->id}/error", ['error' => 'x'])->assertForbidden();
    }

    public function test_el_dueno_consulta_trabajos_pendientes(): void
    {
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);
        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 2]], 'TARJETA');

        $segundaVenta = Venta::query()->where('usuario_id', $cajero->id)->latest('id')->first();
        $trabajo2 = TrabajoImpresion::query()
            ->where('ticket_id', $segundaVenta->ticket->id)
            ->first();
        $trabajo2->update(['estado' => 'IMPRESO', 'impreso_en' => now()]);

        $pendiente = TrabajoImpresion::where('estado', 'PENDIENTE')->first();

        $this->actingAs($dueno)
            ->getJson('/api/impresiones/pendientes')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.estado', 'PENDIENTE')
            ->assertJsonPath('0.numero_ticket', $pendiente->ticket->numero);
    }

    public function test_marcar_como_procesando_evita_la_doble_toma(): void
    {
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        $trabajo = TrabajoImpresion::first();

        $this->actingAs($dueno)
            ->putJson("/api/impresiones/{$trabajo->id}/procesando")
            ->assertOk()
            ->assertJsonPath('estado', 'PROCESANDO')
            ->assertJsonPath('cantidad_intentos', 1);

        $this->actingAs($dueno)
            ->putJson("/api/impresiones/{$trabajo->id}/procesando")
            ->assertStatus(409);

        $trabajo->refresh();
        $this->assertSame('PROCESANDO', $trabajo->estado->value);
        $this->assertSame(1, $trabajo->cantidad_intentos);
    }

    public function test_marcar_como_impreso_guarda_la_fecha_de_impresion(): void
    {
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        $trabajo = TrabajoImpresion::first();
        $this->actingAs($dueno)->putJson("/api/impresiones/{$trabajo->id}/procesando")->assertOk();

        $this->actingAs($dueno)
            ->putJson("/api/impresiones/{$trabajo->id}/impreso")
            ->assertOk()
            ->assertJsonPath('estado', 'IMPRESO');

        $trabajo->refresh();
        $this->assertSame('IMPRESO', $trabajo->estado->value);
        $this->assertNotNull($trabajo->impreso_en);
    }

    public function test_informar_error_guarda_mensaje_y_estado_error(): void
    {
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        $trabajo = TrabajoImpresion::first();
        $this->actingAs($dueno)->putJson("/api/impresiones/{$trabajo->id}/procesando")->assertOk();

        $this->actingAs($dueno)
            ->putJson("/api/impresiones/{$trabajo->id}/error", ['error' => 'Impresora sin papel'])
            ->assertOk()
            ->assertJsonPath('estado', 'ERROR')
            ->assertJsonPath('ultimo_error', 'Impresora sin papel');

        $trabajo->refresh();
        $this->assertSame('ERROR', $trabajo->estado->value);
        $this->assertSame('Impresora sin papel', $trabajo->ultimo_error);
        $this->assertSame(1, $trabajo->cantidad_intentos);
    }

    public function test_el_ticket_marca_el_numero_en_el_flash_del_pos(): void
    {
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        $venta = $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        $this->assertSame(
            'Venta registrada correctamente por $1.200,00. Ticket N° '.$venta->ticket->numero.' en cola de impresión.',
            session('success'),
        );
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
     * @param  list<array{producto_id: int, cantidad: float|string}>  $items
     */
    private function abrirCaja(Usuario $cajero, float $montoInicial = 0.0): void
    {
        app(CajaService::class)->abrir($cajero, $montoInicial);
    }

    private function vender(Usuario $cajero, array $items, string $medioPago = 'EFECTIVO'): Venta
    {
        if (! Caja::query()->abierta()->exists()) {
            app(CajaService::class)->abrir($cajero, 0);
        }

        $datos = ['medio_pago' => $medioPago, 'items' => $items];

        if ($medioPago === 'EFECTIVO') {
            $datos['efectivo_recibido'] = $this->totalDeItems($items);
        }

        $this->actingAs($cajero)
            ->post('/pos/ventas', $datos)
            ->assertRedirect(route('pos.index'));

        return Venta::query()->where('usuario_id', $cajero->id)->latest('id')->first();
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
