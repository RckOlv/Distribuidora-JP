<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Categoria;
use App\Models\DispositivoImpresion;
use App\Models\Permiso;
use App\Models\Precio;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\TrabajoImpresion;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\CajaService;
use App\Support\Permisos as PermisosDisponibles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BridgeImpresionTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------ Autenticación de dispositivo

    public function test_sin_token_se_rechaza(): void
    {
        $this->getJson('/api/bridge/impresiones/pendientes')
            ->assertUnauthorized();

        $this->getJson('/api/bridge/impresiones/pendientes', [
            'Authorization' => 'Bearer token-invalido',
        ])->assertUnauthorized();
    }

    public function test_token_valido_es_aceptado(): void
    {
        $this->crearDispositivo('bridge-001', 'token-secreto');
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        $respuesta = $this->withToken('token-secreto')
            ->getJson('/api/bridge/impresiones/pendientes');

        $respuesta->assertOk();
        $this->assertNotNull($respuesta->json('trabajo.numero_ticket'));
    }

    public function test_dispositivo_desactivado_es_rechazado(): void
    {
        $dispositivo = $this->crearDispositivo('bridge-001', 'token-secreto');
        $dispositivo->update(['activo' => false]);

        $this->withToken('token-secreto')
            ->getJson('/api/bridge/impresiones/pendientes')
            ->assertUnauthorized();
    }

    public function test_ultima_conexion_no_se_actualiza_en_cada_request(): void
    {
        $dispositivo = $this->crearDispositivo('bridge-001', 'token-secreto');

        // Primer request: sí registra la conexión.
        $this->withToken('token-secreto')
            ->getJson('/api/bridge/impresiones/pendientes')
            ->assertOk();

        $primeraEscritura = $dispositivo->refresh()->ultima_conexion;
        $this->assertNotNull($primeraEscritura);

        // Request inmediatamente posterior: dentro del intervalo (~55s) no debe
        // volver a escribir (throttle). Simulamos el polling siguiente.
        $this->withToken('token-secreto')
            ->getJson('/api/bridge/impresiones/pendientes')
            ->assertOk();

        $segundaEscritura = $dispositivo->refresh()->ultima_conexion;
        $this->assertSame(
            $primeraEscritura?->toIso8601String(),
            $segundaEscritura?->toIso8601String(),
            'ultima_conexion no debería cambiar dentro del mismo intervalo.',
        );
    }

    public function test_ultima_conexion_se_actualiza_al_vencer_el_intervalo(): void
    {
        $dispositivo = $this->crearDispositivo('bridge-001', 'token-secreto');

        $this->withToken('token-secreto')
            ->getJson('/api/bridge/impresiones/pendientes')
            ->assertOk();
        $antes = $dispositivo->refresh()->ultima_conexion;

        // Forzamos el vencimiento del intervalo y verificamos que se reescribe.
        Cache::lock("impresion:conexion:{$dispositivo->id}")->forceRelease();

        $this->withToken('token-secreto')
            ->getJson('/api/bridge/impresiones/pendientes')
            ->assertOk();

        $despues = $dispositivo->refresh()->ultima_conexion;
        $this->assertNotNull($despues);
        $this->assertTrue($despues->greaterThanOrEqualTo($antes));
    }

    public function test_todo_el_polling_no_genera_update_en_cada_request(): void
    {
        $dispositivo = $this->crearDispositivo('bridge-001', 'token-secreto');

        // Contamos los UPDATE sobre la tabla del dispositivo disparados por una
        // ráfaga de requests (equivalente a varios polls en pocos segundos).
        $updates = 0;
        DB::listen(function ($query) use (&$updates) {
            if (str_starts_with(trim($query->sql), 'update') &&
                str_contains($query->sql, 'dispositivos_impresion')) {
                $updates++;
            }
        });

        foreach (range(1, 5) as $i) {
            $this->withToken('token-secreto')
                ->getJson('/api/bridge/impresiones/pendientes')
                ->assertOk();
        }

        // Solo el primero escribe; el resto queda dentro del intervalo (lock).
        $this->assertLessThanOrEqual(1, $updates);
    }

    public function test_el_bridge_no_puede_acceder_a_funcionalidades_administrativas(): void
    {
        // El endpoint humano exige autenticación por sesión (no por token).
        $this->withToken('token-secreto')
            ->getJson('/api/impresiones/pendientes')
            ->assertUnauthorized();

        // Y el bridge no puede vender por el POS.
        $this->withToken('token-secreto')
            ->postJson('/pos/ventas', [
                'medio_pago' => 'EFECTIVO',
                'items' => [],
            ])
            ->assertUnauthorized();
    }

    // ------------------------------------------------------------------ Claim atómico

    public function test_el_claim_devuelve_a_lo_sumo_un_trabajo_y_lo_deja_procesando(): void
    {
        $this->crearDispositivo('bridge-001', 'token-secreto');
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);
        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 2]], 'TARJETA');

        $primera = $this->withToken('token-secreto')
            ->getJson('/api/bridge/impresiones/pendientes');

        $primera->assertOk();
        $primera->assertJsonPath('trabajo.estado', 'PROCESANDO');
        $this->assertNotNull($primera->json('trabajo.numero_ticket'));

        $trabajo = TrabajoImpresion::where('ticket_id', Venta::first()->ticket->id)->first();
        $this->assertSame('PROCESANDO', $trabajo->estado->value);
        $this->assertNotNull($trabajo->procesando_at);
        $this->assertNotNull($trabajo->dispositivo_id);
        $this->assertSame(1, $trabajo->cantidad_intentos);
    }

    public function test_dos_bridges_no_reciben_el_mismo_trabajo(): void
    {
        $this->crearDispositivo('bridge-001', 'token-secreto');
        $this->crearDispositivo('bridge-002', 'token-dos');
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        $a = $this->withToken('token-secreto')->getJson('/api/bridge/impresiones/pendientes');
        $b = $this->withToken('token-dos')->getJson('/api/bridge/impresiones/pendientes');

        $a->assertOk();
        $this->assertNotNull($a->json('trabajo'));
        $this->assertSame('PROCESANDO', $a->json('trabajo.estado'));
        // El segundo bridge ve una cola vacía: el trabajo ya está PROCESANDO.
        $b->assertJsonPath('trabajo', null);
    }

    public function test_el_trabajo_incluye_el_snapshot_del_ticket_para_imprimir(): void
    {
        $this->crearDispositivo('bridge-001', 'token-secreto');
        $producto = $this->crearVendible('Banana', 'KILOGRAMO', 2500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1.5]]);

        $respuesta = $this->withToken('token-secreto')
            ->getJson('/api/bridge/impresiones/pendientes');

        $contenido = json_decode($respuesta->json('trabajo.contenido'), true);

        $this->assertSame('Banana', $contenido['detalles'][0]['nombre']);
        $this->assertSame('1.500', $contenido['detalles'][0]['cantidad']);
        $this->assertSame('2500.00', $contenido['detalles'][0]['precio_unitario']);
        $this->assertSame('3750.00', $contenido['total']);
    }

    public function test_el_claim_devuelve_el_tipo_de_un_trabajo_de_venta(): void
    {
        $this->crearDispositivo('bridge-001', 'token-secreto');
        $producto = $this->crearVendible('Banana', 'KILOGRAMO', 2500);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1.5]]);

        $this->withToken('token-secreto')
            ->getJson('/api/bridge/impresiones/pendientes')
            ->assertJsonPath('trabajo.tipo', 'VENTA');
    }

    // ------------------------------------------------------------------ Confirmar impresión

    public function test_informar_impreso_marca_impreso_y_libera_el_claim(): void
    {
        $dispositivo = $this->crearDispositivo('bridge-001', 'token-secreto');
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        $this->withToken('token-secreto')->getJson('/api/bridge/impresiones/pendientes');

        $trabajo = TrabajoImpresion::first();

        $this->withToken('token-secreto')
            ->putJson("/api/bridge/impresiones/{$trabajo->id}/impreso")
            ->assertOk()
            ->assertJsonPath('estado', 'IMPRESO');

        $trabajo->refresh();
        $this->assertSame('IMPRESO', $trabajo->estado->value);
        $this->assertNotNull($trabajo->impreso_en);
        $this->assertNull($trabajo->procesando_at);
    }

    public function test_otro_dispositivo_no_puede_confirmar_un_trabajo_que_no_reclamo(): void
    {
        $this->crearDispositivo('bridge-001', 'token-secreto');
        $this->crearDispositivo('bridge-002', 'token-dos');
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        $this->withToken('token-secreto')->getJson('/api/bridge/impresiones/pendientes');

        $trabajo = TrabajoImpresion::first();

        $this->withToken('token-dos')
            ->putJson("/api/bridge/impresiones/{$trabajo->id}/impreso")
            ->assertForbidden();

        $this->assertSame('PROCESANDO', $trabajo->refresh()->estado->value);
    }

    public function test_no_puede_confirmarse_un_trabajo_que_no_esta_procesando(): void
    {
        $dispositivo = $this->crearDispositivo('bridge-001', 'token-secreto');
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        $trabajo = TrabajoImpresion::first();

        // Nunca se reclamó: sigue PENDIENTE → no se puede marcar impreso.
        $this->withToken('token-secreto')
            ->putJson("/api/bridge/impresiones/{$trabajo->id}/impreso")
            ->assertForbidden();
    }

    // ------------------------------------------------------------------ Error y reintento

    public function test_error_temporal_devuelve_el_trabajo_a_pendiente(): void
    {
        $dispositivo = $this->crearDispositivo('bridge-001', 'token-secreto');
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        $this->withToken('token-secreto')->getJson('/api/bridge/impresiones/pendientes');
        $trabajo = TrabajoImpresion::first();

        $this->withToken('token-secreto')
            ->putJson("/api/bridge/impresiones/{$trabajo->id}/error", [
                'error' => 'Impresora sin papel',
            ])
            ->assertOk()
            ->assertJsonPath('estado', 'PENDIENTE');

        $trabajo->refresh();
        $this->assertSame('PENDIENTE', $trabajo->estado->value);
        $this->assertNull($trabajo->dispositivo_id);
        $this->assertNull($trabajo->procesando_at);
        $this->assertSame('Impresora sin papel', $trabajo->ultimo_error);

        // Disponible de nuevo: otro reclamado ya puede tomarlo.
        $segundo = $this->withToken('token-secreto')->getJson('/api/bridge/impresiones/pendientes');
        $segundo->assertJsonPath('trabajo.id', $trabajo->id);
    }

    public function test_error_permanente_deja_el_trabajo_en_error(): void
    {
        $this->crearDispositivo('bridge-001', 'token-secreto');
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        $this->withToken('token-secreto')->getJson('/api/bridge/impresiones/pendientes');
        $trabajo = TrabajoImpresion::first();

        $this->withToken('token-secreto')
            ->putJson("/api/bridge/impresiones/{$trabajo->id}/error", [
                'error' => 'Error no recuperable',
                'reintentar' => false,
            ])
            ->assertOk()
            ->assertJsonPath('estado', 'ERROR');

        $this->assertSame('ERROR', $trabajo->refresh()->estado->value);
    }

    // ------------------------------------------------------------------ Recuperación de PROCESANDO

    public function test_se_recupera_un_trabajo_procesando_vencido(): void
    {
        $dispositivo = $this->crearDispositivo('bridge-001', 'token-secreto');
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        $trabajo = TrabajoImpresion::first();
        // Simula un claim que quedó huérfano hace más de 5 minutos.
        $trabajo->forceFill([
            'estado' => 'PROCESANDO',
            'dispositivo_id' => $dispositivo->id,
            'procesando_at' => now()->subMinutes(10),
        ])->save();

        $this->withToken('token-secreto')
            ->getJson('/api/bridge/impresiones/pendientes')
            ->assertJsonPath('trabajo.id', $trabajo->id);

        $trabajo->refresh();
        $this->assertSame('PROCESANDO', $trabajo->estado->value);
    }

    public function test_un_procesando_reciente_no_se_recupera_ni_entra_en_la_cola(): void
    {
        $dispositivo = $this->crearDispositivo('bridge-001', 'token-secreto');
        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);

        $trabajo = TrabajoImpresion::first();
        $trabajo->forceFill([
            'estado' => 'PROCESANDO',
            'dispositivo_id' => $dispositivo->id,
            'procesando_at' => now()->subMinute(),
        ])->save();

        $this->withToken('token-secreto')
            ->getJson('/api/bridge/impresiones/pendientes')
            ->assertJsonPath('trabajo', null);

        $this->assertSame('PROCESANDO', $trabajo->refresh()->estado->value);
    }

    // ------------------------------------------------------------------ Helpers

    private function crearDispositivo(string $nombre, string $token): DispositivoImpresion
    {
        return DispositivoImpresion::create([
            'nombre' => $nombre,
            'token_hash' => Hash::make($token),
            'activo' => true,
        ]);
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
     * @param  list<array{producto_id: int, cantidad: float|string}>  $items
     */
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
        bool $activo = true,
        string $categoriaNombre = 'Frutas',
    ): Producto {
        $categoria = $this->crearCategoria($categoriaNombre);

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
