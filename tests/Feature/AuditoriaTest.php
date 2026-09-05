<?php

namespace Tests\Feature;

use App\Enums\AccionAuditoria;
use App\Enums\TipoMovimientoCaja;
use App\Models\Auditoria;
use App\Models\Caja;
use App\Models\Categoria;
use App\Models\Costo;
use App\Models\Permiso;
use App\Models\Precio;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Usuario;
use App\Services\CajaService;
use App\Services\VentaService;
use App\Support\Permisos as PermisosDisponibles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AuditoriaTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------ Acceso

    public function test_un_dueno_puede_acceder_al_historial_de_auditoria(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->get('/auditoria')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Auditoria/Index'));
    }

    public function test_un_cajero_recibe_403(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, [PermisosDisponibles::POS_USAR]);

        $this->actingAs($cajero)->get('/auditoria')->assertForbidden();
    }

    public function test_un_usuario_sin_auditoria_ver_recibe_403(): void
    {
        $usuario = $this->crearUsuarioConRol(Rol::CAJERO, [PermisosDisponibles::VENTAS_VER]);

        $this->actingAs($usuario)->get('/auditoria')->assertForbidden();
    }

    public function test_la_auditoria_no_tiene_rutas_de_escritura(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)->post('/auditoria/1', [])->assertMethodNotAllowed();
        $this->actingAs($dueno)->put('/auditoria/1', [])->assertMethodNotAllowed();
        $this->actingAs($dueno)->patch('/auditoria/1', [])->assertMethodNotAllowed();
        $this->actingAs($dueno)->delete('/auditoria/1')->assertMethodNotAllowed();
    }

    // ------------------------------------------------------------------ Usuarios

    public function test_crear_un_usuario_genera_auditoria(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $rol = Rol::firstOrCreate(['nombre' => Rol::CAJERO]);

        $this->actingAs($dueno)->post('/usuarios', [
            'name' => 'Pepe',
            'email' => 'pepe@ejemplo.com',
            'rol_id' => $rol->id,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'activo' => true,
        ])->assertRedirect();

        $registro = Auditoria::where('accion', AccionAuditoria::USUARIO_CREADO->value)->first();

        $this->assertNotNull($registro);
        $this->assertSame('Pepe', $registro->datos_nuevos['name']);
        $this->assertSame('pepe@ejemplo.com', $registro->datos_nuevos['email']);
        $this->assertSame(Rol::CAJERO, $registro->datos_nuevos['rol']);
        $this->assertTrue($registro->datos_nuevos['activo']);
        $this->assertArrayNotHasKey('password', $registro->datos_nuevos);
    }

    public function test_modificar_un_usuario_genera_auditoria_con_antes_y_despues(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, []);
        $rol = Rol::firstOrCreate(['nombre' => Rol::CAJERO]);

        $this->actingAs($dueno)->put('/usuarios/'.$cajero->id, [
            'name' => 'NuevoNombre',
            'email' => 'nuevo@ejemplo.com',
            'rol_id' => $rol->id,
            'activo' => true,
        ])->assertRedirect();

        $registro = Auditoria::where('accion', AccionAuditoria::USUARIO_MODIFICADO->value)
            ->where('entidad_id', $cajero->id)
            ->first();

        $this->assertNotNull($registro);
        $this->assertArrayHasKey('name', $registro->datos_anteriores);
        $this->assertSame('NuevoNombre', $registro->datos_nuevos['name']);
        $this->assertSame('nuevo@ejemplo.com', $registro->datos_nuevos['email']);
        $this->assertArrayNotHasKey('password', $registro->datos_nuevos);
    }

    public function test_activar_o_desactivar_un_usuario_genera_auditoria(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, []);

        $this->actingAs($dueno)->post('/usuarios/'.$cajero->id.'/estado')->assertRedirect();

        $this->assertDatabaseHas('auditoria', ['accion' => AccionAuditoria::USUARIO_DESACTIVADO->value, 'entidad_id' => $cajero->id]);

        $this->actingAs($dueno)->post('/usuarios/'.$cajero->id.'/estado')->assertRedirect();

        $this->assertDatabaseHas('auditoria', ['accion' => AccionAuditoria::USUARIO_ACTIVADO->value, 'entidad_id' => $cajero->id]);
    }

    public function test_cambiar_la_contrasena_no_guarda_la_contrasena_ni_su_hash(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, []);
        $rol = Rol::firstOrCreate(['nombre' => Rol::CAJERO]);

        $this->actingAs($dueno)->put('/usuarios/'.$cajero->id, [
            'name' => $cajero->name,
            'email' => $cajero->email,
            'rol_id' => $rol->id,
            'activo' => true,
            'password' => 'nuevaClave123',
            'password_confirmation' => 'nuevaClave123',
        ])->assertRedirect();

        $registro = Auditoria::where('accion', AccionAuditoria::USUARIO_MODIFICADO->value)
            ->where('entidad_id', $cajero->id)
            ->latest('id')
            ->first();

        $this->assertTrue($registro->datos_nuevos['password_cambiada']);
        $json = json_encode([$registro->datos_anteriores, $registro->datos_nuevos]);

        $this->assertStringNotContainsString('nuevaClave123', (string) $json);
        $this->assertArrayNotHasKey('password', $registro->datos_nuevos);
        $this->assertArrayNotHasKey('password', $registro->datos_anteriores ?? []);
    }

    // ------------------------------------------------------------------ Productos

    public function test_crear_un_producto_genera_auditoria(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $categoria = Categoria::create(['nombre' => 'Verduras', 'activa' => true]);

        $this->actingAs($dueno)->post('/productos', [
            'nombre' => 'Zanahoria',
            'categoria_id' => $categoria->id,
            'unidad_medida' => 'KILOGRAMO',
            'codigo' => '7790000900',
            'monto' => 500,
            'costo' => 300,
            'activo' => true,
        ])->assertRedirect();

        $registro = Auditoria::where('accion', AccionAuditoria::PRODUCTO_CREADO->value)->first();

        $this->assertNotNull($registro);
        $this->assertSame('Zanahoria', $registro->datos_nuevos['nombre']);
        $this->assertSame($categoria->id, $registro->datos_nuevos['categoria_id']);
    }

    public function test_modificar_un_producto_genera_auditoria(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);

        $this->actingAs($dueno)->put('/productos/'.$producto->id, [
            'nombre' => 'Papa blanca',
            'categoria_id' => $producto->categoria_id,
            'unidad_medida' => 'KILOGRAMO',
            'costo' => 1200,
            'activo' => true,
        ])->assertRedirect();

        $registro = Auditoria::where('accion', AccionAuditoria::PRODUCTO_MODIFICADO->value)
            ->where('entidad_id', $producto->id)
            ->first();

        $this->assertNotNull($registro);
        $this->assertSame('Papa', $registro->datos_anteriores['nombre']);
        $this->assertSame('Papa blanca', $registro->datos_nuevos['nombre']);
    }

    public function test_activar_o_desactivar_un_producto_genera_auditoria(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);

        $this->actingAs($dueno)->post('/productos/'.$producto->id.'/estado')->assertRedirect();
        $this->assertDatabaseHas('auditoria', ['accion' => AccionAuditoria::PRODUCTO_DESACTIVADO->value, 'entidad_id' => $producto->id]);

        $this->actingAs($dueno)->post('/productos/'.$producto->id.'/estado')->assertRedirect();
        $this->assertDatabaseHas('auditoria', ['accion' => AccionAuditoria::PRODUCTO_ACTIVADO->value, 'entidad_id' => $producto->id]);
    }

    // ------------------------------------------------------------------ Categorías

    public function test_crear_una_categoria_genera_auditoria(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)->post('/categorias', ['nombre' => 'Frutas'])->assertRedirect();

        $registro = Auditoria::where('accion', AccionAuditoria::CATEGORIA_CREADA->value)->first();

        $this->assertNotNull($registro);
        $this->assertSame('Frutas', $registro->datos_nuevos['nombre']);
    }

    public function test_modificar_una_categoria_genera_auditoria(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $categoria = Categoria::create(['nombre' => 'Frutas', 'activa' => true]);

        $this->actingAs($dueno)->put('/categorias/'.$categoria->id, ['nombre' => 'Frutas premium'])->assertRedirect();

        $registro = Auditoria::where('accion', AccionAuditoria::CATEGORIA_MODIFICADA->value)
            ->where('entidad_id', $categoria->id)
            ->first();

        $this->assertNotNull($registro);
        $this->assertSame('Frutas', $registro->datos_anteriores['nombre']);
        $this->assertSame('Frutas premium', $registro->datos_nuevos['nombre']);
    }

    public function test_activar_o_desactivar_una_categoria_genera_auditoria(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $categoria = Categoria::create(['nombre' => 'Frutas', 'activa' => true]);

        $this->actingAs($dueno)->post('/categorias/'.$categoria->id.'/estado')->assertRedirect();
        $this->assertDatabaseHas('auditoria', ['accion' => AccionAuditoria::CATEGORIA_DESACTIVADA->value, 'entidad_id' => $categoria->id]);

        $this->actingAs($dueno)->post('/categorias/'.$categoria->id.'/estado')->assertRedirect();
        $this->assertDatabaseHas('auditoria', ['accion' => AccionAuditoria::CATEGORIA_ACTIVADA->value, 'entidad_id' => $categoria->id]);
    }

    // ------------------------------------------------------------------ Precios

    public function test_cambiar_el_precio_genera_precio_modificado(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        // El producto se crea sin precio para que no haya PRECIO_MODIFICADO inicial.
        $categoria = Categoria::create(['nombre' => 'Verduras', 'activa' => true]);
        $producto = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Remolacha', 'unidad_medida' => 'KILOGRAMO', 'activo' => true]);
        Precio::create(['producto_id' => $producto->id, 'monto' => 1000, 'vigente' => true, 'usuario_id' => null]);

        $this->actingAs($dueno)->put('/productos/'.$producto->id, [
            'nombre' => 'Remolacha',
            'categoria_id' => $categoria->id,
            'unidad_medida' => 'KILOGRAMO',
            'monto' => 1500,
            'costo' => 600,
            'activo' => true,
        ])->assertRedirect();

        $registro = Auditoria::where('accion', AccionAuditoria::PRECIO_MODIFICADO->value)->latest('id')->first();

        $this->assertNotNull($registro);
        $this->assertSame(1000, $registro->datos_anteriores['precio_anterior']);
        $this->assertSame(1500, $registro->datos_nuevos['precio_nuevo']);
        $this->assertSame($producto->id, $registro->datos_nuevos['producto_id']);
    }

    public function test_editar_un_producto_sin_cambiar_el_precio_no_genera_precio_modificado(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);

        $this->actingAs($dueno)->put('/productos/'.$producto->id, [
            'nombre' => 'Papa andina',
            'categoria_id' => $producto->categoria_id,
            'unidad_medida' => 'KILOGRAMO',
            'costo' => 600,
            'activo' => true,
        ])->assertRedirect();

        $this->assertDatabaseMissing('auditoria', ['accion' => AccionAuditoria::PRECIO_MODIFICADO->value, 'entidad_id' => $producto->id]);
    }

    public function test_crear_producto_con_precio_inicial_genera_precio_creado_y_no_precio_modificado(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $categoria = Categoria::create(['nombre' => 'Verduras', 'activa' => true]);

        $this->actingAs($dueno)->post('/productos', [
            'nombre' => 'Lechuga',
            'categoria_id' => $categoria->id,
            'unidad_medida' => 'UNIDAD',
            'codigo' => '7790001000',
            'monto' => 800,
            'costo' => 400,
            'activo' => true,
        ])->assertRedirect();

        $producto = Producto::where('nombre', 'Lechuga')->first();

        $this->assertDatabaseHas('auditoria', [
            'accion' => AccionAuditoria::PRECIO_CREADO->value,
            'entidad_tipo' => 'precio',
        ]);

        $this->assertDatabaseMissing('auditoria', [
            'accion' => AccionAuditoria::PRECIO_MODIFICADO->value,
            'entidad_id' => $producto->id,
        ]);

        $registro = Auditoria::where('accion', AccionAuditoria::PRECIO_CREADO->value)
            ->where('entidad_id', $producto->precioVigente->id)
            ->first();
        $this->assertNotNull($registro);
        $this->assertSame(800, $registro->datos_nuevos['precio_nuevo']);
    }

    public function test_crear_producto_sin_precio_no_genera_auditoria_de_precio(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $categoria = Categoria::create(['nombre' => 'Verduras', 'activa' => true]);

        // El formulario exige precio de venta hoy, pero un alta sin precio
        // (compatible con datos históricos) no debe dejar auditoría de precio.
        $producto = Producto::create([
            'categoria_id' => $categoria->id,
            'nombre' => 'Lechuga',
            'unidad_medida' => 'UNIDAD',
            'activo' => true,
        ]);

        $this->assertNull($producto->precioVigente);
        $this->assertDatabaseMissing('auditoria', ['accion' => AccionAuditoria::PRECIO_CREADO->value]);
        $this->assertDatabaseMissing('auditoria', ['accion' => AccionAuditoria::PRECIO_MODIFICADO->value, 'entidad_id' => $producto->id]);
    }

    // ------------------------------------------------------------------ Costos

    public function test_crear_producto_sin_costo_no_genera_auditoria_de_costo(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $categoria = Categoria::create(['nombre' => 'Verduras', 'activa' => true]);

        $producto = Producto::create([
            'categoria_id' => $categoria->id,
            'nombre' => 'Papa',
            'unidad_medida' => 'KILOGRAMO',
            'activo' => true,
        ]);
        Precio::create(['producto_id' => $producto->id, 'monto' => 1000, 'vigente' => true, 'usuario_id' => $dueno->id]);

        $this->assertNull($producto->costoVigente);
        $this->assertDatabaseMissing('auditoria', ['accion' => AccionAuditoria::COSTO_CREADO->value]);
        $this->assertDatabaseMissing('auditoria', ['accion' => AccionAuditoria::COSTO_MODIFICADO->value, 'entidad_id' => $producto->id]);
    }

    public function test_crear_producto_con_costo_inicial_genera_costo_creado(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $categoria = Categoria::create(['nombre' => 'Verduras', 'activa' => true]);

        $this->actingAs($dueno)->post('/productos', [
            'nombre' => 'Papa',
            'categoria_id' => $categoria->id,
            'unidad_medida' => 'KILOGRAMO',
            'codigo' => '7790001100',
            'monto' => 1000,
            'costo' => 700,
            'activo' => true,
        ])->assertRedirect();

        $producto = Producto::where('nombre', 'Papa')->first();

        $this->assertSame(1, Costo::where('producto_id', $producto->id)->count());

        $registro = Auditoria::where('accion', AccionAuditoria::COSTO_CREADO->value)
            ->where('entidad_id', $producto->costoVigente->id)
            ->first();

        $this->assertNotNull($registro);
        $this->assertSame(700, $registro->datos_nuevos['costo_nuevo']);
        $this->assertDatabaseMissing('auditoria', ['accion' => AccionAuditoria::COSTO_MODIFICADO->value, 'entidad_id' => $producto->id]);
    }

    public function test_cambiar_el_costo_genera_costo_modificado_con_anterior_y_nuevo(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);
        Costo::create(['producto_id' => $producto->id, 'precio' => 600, 'vigente' => true, 'usuario_id' => null]);

        $this->actingAs($dueno)->put('/productos/'.$producto->id, [
            'nombre' => 'Papa',
            'categoria_id' => $producto->categoria_id,
            'unidad_medida' => 'KILOGRAMO',
            'costo' => 800,
            'activo' => true,
        ])->assertRedirect();

        $this->assertSame(2, Costo::where('producto_id', $producto->id)->count(), 'El costo anterior debe conservarse en el historial.');
        $this->assertSame(1, Costo::where('producto_id', $producto->id)->where('vigente', true)->count(), 'Solo debe existir un costo vigente.');

        $registro = Auditoria::where('accion', AccionAuditoria::COSTO_MODIFICADO->value)
            ->where('entidad_id', $producto->costoVigente->id)
            ->first();

        $this->assertNotNull($registro);
        $this->assertSame(600, $registro->datos_anteriores['costo_anterior']);
        $this->assertSame(800, $registro->datos_nuevos['costo_nuevo']);
    }

    public function test_editar_sin_cambiar_el_costo_no_genera_costo_modificado(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);
        Costo::create(['producto_id' => $producto->id, 'precio' => 600, 'vigente' => true, 'usuario_id' => null]);

        $this->actingAs($dueno)->put('/productos/'.$producto->id, [
            'nombre' => 'Papa andina',
            'categoria_id' => $producto->categoria_id,
            'unidad_medida' => 'KILOGRAMO',
            'costo' => 600,
            'activo' => true,
        ])->assertRedirect();

        $this->assertSame(1, Costo::where('producto_id', $producto->id)->count());
        $this->assertDatabaseMissing('auditoria', ['accion' => AccionAuditoria::COSTO_MODIFICADO->value, 'entidad_id' => $producto->id]);
    }

    public function test_asignar_costo_a_producto_sin_costo_previo_genera_costo_creado(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);

        $this->actingAs($dueno)->put('/productos/'.$producto->id, [
            'nombre' => 'Papa',
            'categoria_id' => $producto->categoria_id,
            'unidad_medida' => 'KILOGRAMO',
            'costo' => 650,
            'activo' => true,
        ])->assertRedirect();

        $this->assertSame(1, Costo::where('producto_id', $producto->id)->count());
        $this->assertDatabaseHas('auditoria', [
            'accion' => AccionAuditoria::COSTO_CREADO->value,
            'entidad_id' => $producto->costoVigente->id,
        ]);
        $this->assertDatabaseMissing('auditoria', ['accion' => AccionAuditoria::COSTO_MODIFICADO->value, 'entidad_id' => $producto->id]);
    }

    public function test_cajero_no_puede_modificar_costo_ni_precio_de_venta_en_peticion_directa(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, [
            'ventas.realizar',
            'ventas.ver',
            'tickets.imprimir',
        ]);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);
        $costoId = Costo::create(['producto_id' => $producto->id, 'precio' => 600, 'vigente' => true, 'usuario_id' => null])->id;

        $this->actingAs($cajero)->put('/productos/'.$producto->id, [
            'nombre' => 'Papa',
            'categoria_id' => $producto->categoria_id,
            'unidad_medida' => 'KILOGRAMO',
            'monto' => 1500,
            'costo' => 900,
            'activo' => true,
        ])->assertForbidden();

        $this->assertSame(1, Precio::where('producto_id', $producto->id)->count(), 'El precio no debe cambiar.');
        $this->assertSame(1000.0, (float) $producto->fresh()->precioVigente->monto);
        $this->assertSame(1, Costo::where('producto_id', $producto->id)->count(), 'El costo no debe cambiar.');
        $this->assertSame(600.0, (float) Costo::findOrFail($costoId)->precio);

        $this->assertDatabaseMissing('auditoria', ['accion' => AccionAuditoria::PRECIO_MODIFICADO->value]);
        $this->assertDatabaseMissing('auditoria', ['accion' => AccionAuditoria::COSTO_MODIFICADO->value]);
    }

    // ------------------------------------------------------------------ Caja

    public function test_abrir_caja_genera_auditoria_con_monto_inicial(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, []);
        $this->actingAs($cajero);

        $caja = $this->abrirCaja($cajero, 10000);

        $registro = Auditoria::where('accion', AccionAuditoria::CAJA_ABIERTA->value)->first();

        $this->assertNotNull($registro);
        $this->assertSame($caja->id, $registro->datos_nuevos['caja_id']);
        $this->assertSame(10000, $registro->datos_nuevos['monto_inicial']);
    }

    public function test_un_ingreso_genera_auditoria(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, []);
        $caja = $this->abrirCaja($cajero, 0);

        $this->registrarManual($cajero, $caja, 'INGRESO', 5000, 'Aporte');

        $this->assertDatabaseHas('auditoria', ['accion' => AccionAuditoria::INGRESO_CAJA->value]);
        $registro = Auditoria::where('accion', AccionAuditoria::INGRESO_CAJA->value)->first();
        $this->assertSame(5000, $registro->datos_nuevos['monto']);
        $this->assertSame('Aporte', $registro->datos_nuevos['concepto']);
    }

    public function test_un_egreso_genera_auditoria(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, []);
        $caja = $this->abrirCaja($cajero, 10000);

        $this->registrarManual($cajero, $caja, 'EGRESO', 2000, 'Gasto');

        $registro = Auditoria::where('accion', AccionAuditoria::EGRESO_CAJA->value)->first();
        $this->assertNotNull($registro);
        $this->assertSame(2000, $registro->datos_nuevos['monto']);
    }

    public function test_cerrar_caja_genera_auditoria_con_esperado_contado_y_diferencia(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, []);
        $this->actingAs($cajero);
        $caja = $this->abrirCaja($cajero, 10000);

        $this->cerrarCaja($cajero, $caja, 12000);

        $registro = Auditoria::where('accion', AccionAuditoria::CAJA_CERRADA->value)->first();

        $this->assertNotNull($registro);
        $this->assertSame(10000, $registro->datos_nuevos['efectivo_esperado']);
        $this->assertSame(12000, $registro->datos_nuevos['efectivo_contado']);
        $this->assertSame(2000, $registro->datos_nuevos['diferencia']);
    }

    // ------------------------------------------------------------------ Ventas

    public function test_realizar_una_venta_genera_venta_realizada_con_datos_relevantes(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, []);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);
        $caja = $this->abrirCaja($cajero, 0);

        $venta = app(VentaService::class)->registrar($cajero, 'EFECTIVO', [['producto_id' => $producto->id, 'cantidad' => 2]], 2000);

        $registro = Auditoria::where('accion', AccionAuditoria::VENTA_REALIZADA->value)->first();

        $this->assertNotNull($registro);
        $this->assertSame($venta->id, $registro->datos_nuevos['venta_id']);
        $this->assertSame($cajero->id, $registro->datos_nuevos['usuario_id']);
        $this->assertSame($caja->id, $registro->datos_nuevos['caja_id']);
        $this->assertSame('EFECTIVO', $registro->datos_nuevos['medio_pago']);
        $this->assertSame(2000, $registro->datos_nuevos['total']);
    }

    public function test_el_detalle_de_auditoria_muestra_el_nombre_del_usuario_en_los_datos(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);
        $caja = $this->abrirCaja($dueno, 0);

        $venta = app(VentaService::class)->registrar($dueno, 'EFECTIVO', [['producto_id' => $producto->id, 'cantidad' => 2]], 2000);
        $registro = Auditoria::where('accion', AccionAuditoria::VENTA_REALIZADA->value)->first();

        $this->assertNotNull($registro);

        $this->actingAs($dueno)
            ->get('/auditoria/'.$registro->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auditoria/Show')
                ->where('registro.datos_nuevos.usuario_id', $dueno->name)
                ->where('registro.datos_nuevos.caja_id', $caja->id)
                ->where('registro.datos_nuevos.venta_id', $venta->id));
    }

    public function test_una_venta_que_hace_rollback_no_genera_auditoria(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, []);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);

        // Sin caja abierta: la venta falla dentro de la transacción.
        try {
            app(VentaService::class)->registrar($cajero, 'EFECTIVO', [['producto_id' => $producto->id, 'cantidad' => 1]], 1000);
            $this->fail('La venta debía fallar por falta de caja.');
        } catch (ValidationException) {
            // esperado
        }

        $this->assertDatabaseMissing('auditoria', ['accion' => AccionAuditoria::VENTA_REALIZADA->value]);
    }

    public function test_una_venta_crea_una_unica_auditoria(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, []);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);
        $this->abrirCaja($cajero, 0);

        app(VentaService::class)->registrar($cajero, 'EFECTIVO', [['producto_id' => $producto->id, 'cantidad' => 1]], 1000);

        $this->assertSame(1, Auditoria::where('accion', AccionAuditoria::VENTA_REALIZADA->value)->count());
    }

    // ------------------------------------------------------------------ Integridad y consultas

    public function test_una_operacion_fallida_no_deja_auditoria(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, []);
        $this->actingAs($cajero);
        $caja = $this->abrirCaja($cajero, 0);
        $this->cerrarCaja($cajero, $caja, 0);

        $cantidadAntes = Auditoria::count();

        // Intentar cerrar una caja ya cerrada falla (no se crea auditoría de cierre).
        try {
            $this->cerrarCaja($cajero, $caja, 0);
            $this->fail('Cerrar una caja ya cerrada debía fallar.');
        } catch (ValidationException) {
            // esperado
        }

        $this->assertSame($cantidadAntes, Auditoria::count());
    }

    public function test_los_filtros_del_historial_funcionan(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);
        $this->abrirCaja($dueno, 0);
        app(VentaService::class)->registrar($dueno, 'EFECTIVO', [['producto_id' => $producto->id, 'cantidad' => 1]], 1000);

        $this->actingAs($dueno)
            ->get('/auditoria?accion=VENTA_REALIZADA')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('registros.data', 1)
                ->where('registros.data.0.accion', 'VENTA_REALIZADA'));

        $this->actingAs($dueno)
            ->get('/auditoria?accion=PRODUCTO_CREADO')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('registros.total', 0));
    }

    public function test_las_auditorias_se_mantienen_al_cambiar_filtros(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->get('/auditoria?accion=USUARIO_CREADO')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filtros.accion', 'USUARIO_CREADO'));
    }

    public function test_funciona_la_paginacion(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        for ($i = 0; $i < 20; $i++) {
            Auditoria::create([
                'usuario_id' => $dueno->id,
                'accion' => AccionAuditoria::USUARIO_CREADO->value,
                'entidad_tipo' => 'usuario',
                'descripcion' => "Registro {$i}",
            ]);
        }

        $this->actingAs($dueno)
            ->get('/auditoria')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('registros.data', 10)
                ->where('registros.total', 20));

        $this->actingAs($dueno)
            ->get('/auditoria?page=2')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('registros.data', 10));
    }

    public function test_el_detalle_de_auditoria_funciona(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)->post('/categorias', ['nombre' => 'Detalle'])->assertRedirect();

        $registro = Auditoria::where('accion', AccionAuditoria::CATEGORIA_CREADA->value)->first();

        $this->actingAs($dueno)
            ->get('/auditoria/'.$registro->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auditoria/Show')
                ->where('registro.id', $registro->id)
                ->where('registro.accion', 'CATEGORIA_CREADA'));
    }

    public function test_las_auditorias_aparecen_ordenadas_de_mas_reciente_a_mas_antigua(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        // Forzamos dos auditorías con fechas distintas.
        $a = Auditoria::create(['usuario_id' => $dueno->id, 'accion' => AccionAuditoria::USUARIO_CREADO->value, 'entidad_tipo' => 'usuario']);
        $a->forceFill(['created_at' => Carbon::parse('2026-01-01 10:00:00')])->save();

        $b = Auditoria::create(['usuario_id' => $dueno->id, 'accion' => AccionAuditoria::USUARIO_CREADO->value, 'entidad_tipo' => 'usuario']);
        $b->forceFill(['created_at' => Carbon::parse('2026-03-01 10:00:00')])->save();

        $this->actingAs($dueno)
            ->get('/auditoria')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('registros.data.0.id', $b->id)
                ->where('registros.data.1.id', $a->id));
    }

    public function test_los_registros_no_pueden_modificarse_ni_eliminarse_desde_http(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $registro = Auditoria::create(['usuario_id' => $dueno->id, 'accion' => AccionAuditoria::USUARIO_CREADO->value, 'entidad_tipo' => 'usuario']);

        $this->actingAs($dueno)->post('/auditoria/'.$registro->id, ['descripcion' => 'x'])->assertMethodNotAllowed();
        $this->actingAs($dueno)->put('/auditoria/'.$registro->id, ['descripcion' => 'x'])->assertMethodNotAllowed();
        $this->actingAs($dueno)->delete('/auditoria/'.$registro->id)->assertMethodNotAllowed();

        $this->assertDatabaseHas('auditoria', ['id' => $registro->id, 'descripcion' => null]);
    }

    public function test_el_detalle_conserva_antes_y_despues_sin_perder_informacion(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);

        $this->actingAs($dueno)->put('/productos/'.$producto->id, [
            'nombre' => 'Papa blanca',
            'categoria_id' => $producto->categoria_id,
            'unidad_medida' => 'KILOGRAMO',
            'costo' => 1200,
            'activo' => true,
        ])->assertRedirect();

        $registro = Auditoria::where('accion', AccionAuditoria::PRODUCTO_MODIFICADO->value)
            ->where('entidad_id', $producto->id)
            ->first();

        $this->assertNotNull($registro);

        $this->actingAs($dueno)
            ->get('/auditoria/'.$registro->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auditoria/Show')
                ->where('registro.datos_anteriores.nombre', 'Papa')
                ->where('registro.datos_anteriores.unidad_medida', 'KILOGRAMO')
                ->where('registro.datos_anteriores.activo', true)
                ->where('registro.datos_nuevos.nombre', 'Papa blanca')
                ->where('registro.datos_nuevos.unidad_medida', 'KILOGRAMO')
                ->where('registro.datos_nuevos.activo', true)
                ->where('registro.datos_nuevos.categoria_id', $producto->categoria_id));
    }

    public function test_el_detalle_conserva_valores_null_booleanos_y_montos(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)->post('/categorias', ['nombre' => 'Frutas'])->assertRedirect();

        $registro = Auditoria::where('accion', AccionAuditoria::CATEGORIA_CREADA->value)->first();
        $this->assertNotNull($registro);

        $this->actingAs($dueno)
            ->get('/auditoria/'.$registro->id)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('registro.datos_nuevos.nombre', 'Frutas')
                ->where('registro.datos_nuevos.activa', false)
                ->where('registro.datos_nuevos.descripcion', null));

        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);
        $this->actingAs($dueno)->put('/productos/'.$producto->id, [
            'nombre' => 'Papa',
            'categoria_id' => $producto->categoria_id,
            'unidad_medida' => 'KILOGRAMO',
            'monto' => 1500,
            'costo' => 600,
            'activo' => true,
        ])->assertRedirect();

        $precio = Auditoria::where('accion', AccionAuditoria::PRECIO_MODIFICADO->value)
            ->where('entidad_id', $producto->precioVigente->id)
            ->first();
        $this->assertNotNull($precio);

        $this->actingAs($dueno)
            ->get('/auditoria/'.$precio->id)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('registro.datos_anteriores.precio_anterior', 1000)
                ->where('registro.datos_nuevos.precio_nuevo', 1500)
                ->where('registro.datos_anteriores.producto', 'Papa')
                ->where('registro.datos_nuevos.vigente', true));
    }

    // ------------------------------------------------------------------ Helpers

    private function abrirCaja(Usuario $usuario, float $monto): Caja
    {
        return app(CajaService::class)->abrir($usuario, $monto);
    }

    private function cerrarCaja(Usuario $usuario, Caja $caja, float $contado): void
    {
        app(CajaService::class)->cerrar($usuario, $caja, $contado, 'Cierre de prueba');
    }

    private function registrarManual(Usuario $usuario, Caja $caja, string $tipo, float $monto, string $concepto): void
    {
        app(CajaService::class)->registrarManual($caja, $usuario, TipoMovimientoCaja::from($tipo), $monto, $concepto);
    }

    private function crearVendible(string $nombre, string $unidad, float $monto, string $categoriaNombre = 'Frutas'): Producto
    {
        $categoria = Categoria::firstOrCreate(['nombre' => $categoriaNombre], ['activa' => true]);

        $producto = Producto::create([
            'categoria_id' => $categoria->id,
            'nombre' => $nombre,
            'unidad_medida' => $unidad,
            'activo' => true,
        ]);

        Precio::create(['producto_id' => $producto->id, 'monto' => $monto, 'vigente' => true, 'usuario_id' => null]);

        return $producto;
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
