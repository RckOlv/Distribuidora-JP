<?php

namespace Tests\Feature;

use App\Enums\AccionAuditoria;
use App\Enums\TipoMovimientoCaja;
use App\Models\Auditoria;
use App\Models\Caja;
use App\Models\Categoria;
use App\Models\Permiso;
use App\Models\Precio;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Usuario;
use App\Services\CajaService;
use App\Services\VentaService;
use App\Support\Permisos as PermisosDisponibles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SmokeAuditoriaTest extends TestCase
{
    use RefreshDatabase;

    public function test_flujo_completo_queda_auditado_y_la_interfaz_responde(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, [
            PermisosDisponibles::POS_USAR,
            PermisosDisponibles::CAJAS_USAR,
        ]);
        $rolCajero = Rol::firstOrCreate(['nombre' => Rol::CAJERO]);

        // 1. Crear producto (vía HTTP) con precio inicial.
        $categoria = Categoria::create(['nombre' => 'Smoke', 'activa' => true]);
        $this->actingAs($dueno)->post('/productos', [
            'nombre' => 'Pimiento',
            'categoria_id' => $categoria->id,
            'unidad_medida' => 'KILOGRAMO',
            'codigo' => '7790001200',
            'monto' => 800,
            'costo' => 400,
            'activo' => true,
        ])->assertRedirect();
        $producto = Producto::where('nombre', 'Pimiento')->first();

        // 2. Modificarlo y cambiar precio, y asignar un costo inicial.
        $this->actingAs($dueno)->put('/productos/'.$producto->id, [
            'nombre' => 'Pimiento rojo',
            'categoria_id' => $categoria->id,
            'unidad_medida' => 'KILOGRAMO',
            'monto' => 900,
            'costo' => 600,
            'activo' => true,
        ])->assertRedirect();

        // 2b. Cambiar el costo.
        $this->actingAs($dueno)->put('/productos/'.$producto->id, [
            'nombre' => 'Pimiento rojo',
            'categoria_id' => $categoria->id,
            'unidad_medida' => 'KILOGRAMO',
            'costo' => 650,
            'activo' => true,
        ])->assertRedirect();

        // 2c. Crear otro producto con precio y costo iniciales.
        $this->actingAs($dueno)->post('/productos', [
            'nombre' => 'Cebolla',
            'categoria_id' => $categoria->id,
            'unidad_medida' => 'KILOGRAMO',
            'codigo' => '7790001201',
            'monto' => 700,
            'costo' => 400,
            'activo' => true,
        ])->assertRedirect();

        // 3. Crear usuario, modificarlo y desactivarlo.
        $this->actingAs($dueno)->post('/usuarios', [
            'name' => 'Nuevo',
            'email' => 'nuevo@coop.com',
            'rol_id' => $rolCajero->id,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'activo' => true,
        ])->assertRedirect();
        $nuevo = Usuario::where('email', 'nuevo@coop.com')->first();
        $this->actingAs($dueno)->put('/usuarios/'.$nuevo->id, [
            'name' => 'Nuevo 2',
            'email' => 'nuevo@coop.com',
            'rol_id' => $rolCajero->id,
            'activo' => true,
        ])->assertRedirect();
        $this->actingAs($dueno)->post('/usuarios/'.$nuevo->id.'/estado')->assertRedirect();

        // 4-7. Caja + ingreso + egreso + venta (servicios).
        $this->actingAs($cajero);
        $caja = app(CajaService::class)->abrir($cajero, 10000);
        app(CajaService::class)->registrarManual($caja, $cajero, TipoMovimientoCaja::INGRESO, 5000, 'Aporte');
        app(CajaService::class)->registrarManual($caja, $cajero, TipoMovimientoCaja::EGRESO, 1000, 'Flete');
        $items = [['producto_id' => $producto->id, 'cantidad' => 2]];
        app(VentaService::class)->registrar($cajero, 'EFECTIVO', $items, $this->totalDeItems($items));
        app(CajaService::class)->cerrar($cajero, $caja, 20000, 'Cierre exhaustivo de prueba');

        // Verificaciones de datos auditados.
        $this->assertSame(2, Auditoria::where('accion', AccionAuditoria::PRODUCTO_CREADO->value)->count());
        $this->assertSame(2, Auditoria::where('accion', AccionAuditoria::PRODUCTO_MODIFICADO->value)->count());
        // Crear producto con precio inicial se audita como PRECIO_CREADO (no PRECIO_MODIFICADO).
        $this->assertSame(2, Auditoria::where('accion', AccionAuditoria::PRECIO_CREADO->value)->where('entidad_tipo', 'precio')->count());
        $this->assertSame(1, Auditoria::where('accion', AccionAuditoria::PRECIO_MODIFICADO->value)->where('entidad_tipo', 'precio')->count());
        // Costos: inicial de Pimiento y Cebolla (COSTO_CREADO) + dos cambios (COSTO_MODIFICADO).
        $this->assertSame(2, Auditoria::where('accion', AccionAuditoria::COSTO_CREADO->value)->where('entidad_tipo', 'costo')->count());
        $this->assertSame(2, Auditoria::where('accion', AccionAuditoria::COSTO_MODIFICADO->value)->where('entidad_tipo', 'costo')->count());
        $this->assertSame(1, Auditoria::where('accion', AccionAuditoria::USUARIO_CREADO->value)->count());
        $this->assertSame(1, Auditoria::where('accion', AccionAuditoria::USUARIO_MODIFICADO->value)->count());
        $this->assertSame(1, Auditoria::where('accion', AccionAuditoria::USUARIO_DESACTIVADO->value)->count());
        $this->assertSame(1, Auditoria::where('accion', AccionAuditoria::CAJA_ABIERTA->value)->count());
        $this->assertSame(1, Auditoria::where('accion', AccionAuditoria::INGRESO_CAJA->value)->count());
        $this->assertSame(1, Auditoria::where('accion', AccionAuditoria::EGRESO_CAJA->value)->count());
        $this->assertSame(1, Auditoria::where('accion', AccionAuditoria::VENTA_REALIZADA->value)->count());
        $this->assertSame(1, Auditoria::where('accion', AccionAuditoria::CAJA_CERRADA->value)->count());

        // Ningún registro guarda secretos.
        foreach (Auditoria::all() as $registro) {
            $json = json_encode([$registro->datos_anteriores, $registro->datos_nuevos]);
            $this->assertStringNotContainsString('password123', (string) $json);
        }

        // DUENO consulta el historial y ve las acciones.
        $this->actingAs($dueno)->get('/auditoria')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auditoria/Index')
                ->where('registros.total', Auditoria::count()));

        // Detalle de una caja abierta.
        $ingreso = Auditoria::where('accion', AccionAuditoria::INGRESO_CAJA->value)->first();
        $this->actingAs($dueno)->get('/auditoria/'.$ingreso->id)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auditoria/Show')
                ->where('registro.id', $ingreso->id)
                ->where('registro.accion', 'INGRESO_CAJA'));

        // CAJERO no puede consultar auditoría.
        $this->actingAs($cajero)->get('/auditoria')->assertForbidden();
        $this->actingAs($cajero)->get('/auditoria/'.$ingreso->id)->assertForbidden();
    }

    private function abrirCaja(Usuario $usuario, float $monto): Caja
    {
        return app(CajaService::class)->abrir($usuario, $monto);
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
