<?php

namespace Tests\Feature;

use App\Enums\EstadoCaja;
use App\Enums\MedioPago;
use App\Enums\TipoMovimientoCaja;
use App\Models\Caja;
use App\Models\CajaFisica;
use App\Models\Categoria;
use App\Models\MovimientoCaja;
use App\Models\Permiso;
use App\Models\Precio;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\CajaService;
use App\Services\VentaService;
use Database\Seeders\CajaFisicaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CajasUsuariosTest extends TestCase
{
    use RefreshDatabase;

    public function test_cada_cajero_tiene_su_propia_sesion_abierta_y_aislada(): void
    {
        $this->seed(CajaFisicaSeeder::class);

        $cajeroA = $this->crearUsuarioConRol(Rol::CAJERO, []);
        $cajeroB = $this->crearUsuarioConRol(Rol::CAJERO, []);

        $cajaA = $this->abrirCaja($cajeroA, 1000);
        $cajaB = $this->abrirCaja($cajeroB, 2000);

        $this->assertNotSame($cajaA->id, $cajaB->id);
        $this->assertSame($cajaA->id, app(CajaService::class)->actualDelUsuario($cajeroA)->id);
        $this->assertSame($cajaB->id, app(CajaService::class)->actualDelUsuario($cajeroB)->id);
        $this->assertSame(2, Caja::abierta()->count());
    }

    public function test_cada_sesion_abierta_usa_una_caja_fisica_distinta(): void
    {
        $this->seed(CajaFisicaSeeder::class);

        $cajaA = $this->abrirCaja($this->crearUsuarioConRol(Rol::CAJERO, []), 0);
        $cajaB = $this->abrirCaja($this->crearUsuarioConRol(Rol::CAJERO, []), 0);

        $this->assertNotSame($cajaA->caja_fisica_id, $cajaB->caja_fisica_id);
        $this->assertSame('Caja 1', $cajaA->cajaFisica->nombre);
        $this->assertSame('Caja 2', $cajaB->cajaFisica->nombre);
    }

    public function test_un_cajero_no_puede_abrir_una_segunda_caja_mientras_tiene_una_abierta(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, []);
        $this->abrirCaja($cajero, 1000);

        $this->expectException(ValidationException::class);
        $this->abrirCaja($cajero, 2000);
    }

    public function test_un_cajero_no_puede_registrar_movimientos_en_la_caja_de_otro_cajero(): void
    {
        $this->seed(CajaFisicaSeeder::class);

        $cajeroA = $this->crearUsuarioConRol(Rol::CAJERO, []);
        $cajeroB = $this->crearUsuarioConRol(Rol::CAJERO, []);
        $cajaB = $this->abrirCaja($cajeroB, 0);

        try {
            app(CajaService::class)->registrarManual(
                $cajaB,
                $cajeroA,
                TipoMovimientoCaja::INGRESO,
                500,
                'Intento ajeno',
            );
            $this->fail('No debió permitir operar una caja ajena.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('caja', $e->errors());
            $this->assertSame(0, MovimientoCaja::count());
        }
    }

    public function test_un_cajero_no_puede_cerrar_la_caja_de_otro_cajero(): void
    {
        $this->seed(CajaFisicaSeeder::class);

        $cajeroA = $this->crearUsuarioConRol(Rol::CAJERO, []);
        $cajeroB = $this->crearUsuarioConRol(Rol::CAJERO, []);
        $cajaB = $this->abrirCaja($cajeroB, 0);

        try {
            app(CajaService::class)->cerrar($cajeroA, $cajaB, 0);
            $this->fail('No debió permitir cerrar una caja ajena.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('caja', $e->errors());
            $cajaB->refresh();
            $this->assertSame(EstadoCaja::ABIERTA->value, $cajaB->estado->value);
        }
    }

    public function test_el_dueno_puede_operar_cualquier_caja(): void
    {
        $this->seed(CajaFisicaSeeder::class);

        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $cajaB = $this->abrirCaja($this->crearUsuarioConRol(Rol::CAJERO, []), 0);

        $movimiento = app(CajaService::class)->registrarManual(
            $cajaB,
            $dueno,
            TipoMovimientoCaja::INGRESO,
            500,
            'Aporte del dueño',
        );

        $this->assertSame($cajaB->id, $movimiento->caja_id);
        $this->assertSame(1, MovimientoCaja::count());
    }

    public function test_la_venta_de_un_cajero_se_registra_en_su_propia_caja(): void
    {
        $this->seed(CajaFisicaSeeder::class);

        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);

        $cajeroA = $this->crearUsuarioConRol(Rol::CAJERO, []);
        $cajeroB = $this->crearUsuarioConRol(Rol::CAJERO, []);
        $cajaA = $this->abrirCaja($cajeroA, 0);
        $cajaB = $this->abrirCaja($cajeroB, 0);

        $venta = app(VentaService::class)->registrar(
            $cajeroA,
            MedioPago::EFECTIVO->value,
            [['producto_id' => $producto->id, 'cantidad' => 1]],
            1000,
        );

        $this->assertSame($cajaA->id, $venta->caja_id);
        $this->assertNotSame($cajaB->id, $venta->caja_id);
        $this->assertSame(1, Venta::count());
        $this->assertSame(1, MovimientoCaja::where('caja_id', $cajaA->id)->count());
        $this->assertSame(0, MovimientoCaja::where('caja_id', $cajaB->id)->count());
    }

    public function test_la_venta_pendiente_de_un_cajero_se_asocia_a_su_caja(): void
    {
        $this->seed(CajaFisicaSeeder::class);

        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);

        $cajeroA = $this->crearUsuarioConRol(Rol::CAJERO, []);
        $cajaA = $this->abrirCaja($cajeroA, 0);

        $pendiente = app(VentaService::class)->registrarPendiente(
            $cajeroA,
            MedioPago::EFECTIVO->value,
            [['producto_id' => $producto->id, 'cantidad' => 1]],
        );

        $this->assertSame($cajaA->id, $pendiente->caja_id);

        // Mientras está pendiente no contabiliza movimiento de caja.
        $this->assertSame(0, MovimientoCaja::count());

        // Al confirmar el pago, el movimiento se registra en la caja original.
        app(VentaService::class)->confirmarPago($cajeroA, $pendiente);
        $this->assertSame(
            1,
            MovimientoCaja::where('caja_id', $cajaA->id)->where('venta_id', $pendiente->id)->count(),
        );
    }

    public function test_el_resumen_de_una_caja_no_mezcla_los_datos_de_otra(): void
    {
        $this->seed(CajaFisicaSeeder::class);

        $producto = $this->crearVendible('Papa', 'KILOGRAMO', 1000);

        $cajeroA = $this->crearUsuarioConRol(Rol::CAJERO, []);
        $cajeroB = $this->crearUsuarioConRol(Rol::CAJERO, []);
        $cajaA = $this->abrirCaja($cajeroA, 0);
        $cajaB = $this->abrirCaja($cajeroB, 0);

        app(VentaService::class)->registrar(
            $cajeroA,
            MedioPago::EFECTIVO->value,
            [['producto_id' => $producto->id, 'cantidad' => 1]],
            1000,
        );

        $resumenA = app(CajaService::class)->resumen($cajaA);
        $resumenB = app(CajaService::class)->resumen($cajaB);

        $this->assertSame(1000.0, $resumenA['efectivo_esperado']);
        $this->assertSame(1, $resumenA['cantidad_ventas']);
        $this->assertSame(0.0, $resumenB['efectivo_esperado']);
        $this->assertSame(0, $resumenB['cantidad_ventas']);
    }

    public function test_un_cajero_no_puede_abrir_una_caja_fisica_que_otro_ya_tiene_abierta(): void
    {
        $cajaFisica = CajaFisica::factory()->create();
        $cajeroA = $this->crearUsuarioConRol(Rol::CAJERO, []);
        $cajeroB = $this->crearUsuarioConRol(Rol::CAJERO, []);

        $this->abrirCaja($cajeroA, 0, $cajaFisica);

        try {
            $this->abrirCaja($cajeroB, 0, $cajaFisica);
            $this->fail('No debió permitir abrir una caja física ocupada.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('caja', $e->errors());
        }
    }

    // ------------------------------------------------------------------ Helpers

    private function abrirCaja(Usuario $usuario, float $montoInicial = 0.0, ?CajaFisica $cajaFisica = null): Caja
    {
        return app(CajaService::class)->abrir($usuario, $montoInicial, $cajaFisica);
    }

    private function crearVendible(string $nombre, string $unidad, float $monto): Producto
    {
        $categoria = Categoria::firstOrCreate(['nombre' => 'Frutas'], ['activa' => true]);

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
        ]);

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
