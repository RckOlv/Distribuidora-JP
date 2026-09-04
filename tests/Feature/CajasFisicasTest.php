<?php

namespace Tests\Feature;

use App\Enums\EstadoCaja;
use App\Enums\TipoMovimientoCaja;
use App\Models\Caja;
use App\Models\CajaFisica;
use App\Models\Categoria;
use App\Models\MovimientoCaja;
use App\Models\Precio;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\CajaService;
use Database\Seeders\CajaFisicaSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CajasFisicasTest extends TestCase
{
    use RefreshDatabase;

    public function test_dos_cajas_fisicas_pueden_tener_sesiones_abiertas_simultaneamente(): void
    {
        $caja1 = CajaFisica::factory()->create();
        $caja2 = CajaFisica::factory()->create();
        $usuario = $this->crearUsuario(Rol::CAJERO);
        $otroUsuario = $this->crearUsuario(Rol::CAJERO);

        Caja::create($this->datosCaja($usuario, $caja1));
        Caja::create($this->datosCaja($otroUsuario, $caja2));

        $abiertas = Caja::abierta()->count();
        $this->assertSame(2, $abiertas);
    }

    public function test_una_misma_caja_fisica_no_puede_tener_dos_sesiones_abiertas(): void
    {
        $caja = CajaFisica::factory()->create();
        $usuario = $this->crearUsuario(Rol::CAJERO);

        Caja::create($this->datosCaja($usuario, $caja));

        $this->expectException(QueryException::class);
        Caja::create($this->datosCaja($usuario, $caja));
    }

    public function test_cada_sesion_abierta_queda_asociada_a_su_caja_fisica(): void
    {
        $caja1 = CajaFisica::factory()->create();
        $caja2 = CajaFisica::factory()->create();
        $usuario = $this->crearUsuario(Rol::CAJERO);
        $otroUsuario = $this->crearUsuario(Rol::CAJERO);

        $sesion1 = Caja::create($this->datosCaja($usuario, $caja1));
        $sesion2 = Caja::create($this->datosCaja($otroUsuario, $caja2));

        $this->assertSame($caja1->id, $sesion1->caja_fisica_id);
        $this->assertSame($caja2->id, $sesion2->caja_fisica_id);
        $this->assertSame($sesion1->id, $caja1->cajas()->first()->id);
        $this->assertSame($sesion2->id, $caja2->cajas()->first()->id);
    }

    public function test_backfill_preserva_sesiones_historicas_y_les_asigna_caja_uno(): void
    {
        // Simula el estado histórico previo: sesión sin caja física asignada,
        // con una venta y un movimiento asociados.
        $usuario = $this->crearUsuario(Rol::CAJERO);
        $sesionHistorica = Caja::create([
            'usuario_abre_id' => $usuario->id,
            'estado' => EstadoCaja::CERRADA,
            'monto_inicial' => 1000,
            'abierta_en' => now()->subDay(),
            'cerrada_en' => now()->subDay()->addHours(8),
        ]);

        $producto = $this->crearVendible('Gaseosa', 'UNIDAD', 1200);
        $venta = Venta::create([
            'usuario_id' => $usuario->id,
            'caja_id' => $sesionHistorica->id,
            'medio_pago' => 'EFECTIVO',
            'total' => 1200,
            'estado_pago' => 'PAGADA',
        ]);
        $movimiento = MovimientoCaja::create([
            'caja_id' => $sesionHistorica->id,
            'venta_id' => $venta->id,
            'tipo' => TipoMovimientoCaja::VENTA,
            'monto' => 1200,
            'concepto' => 'Venta',
        ]);

        $this->assertNull($sesionHistorica->caja_fisica_id);

        // Ejecuta el mismo backfill que realiza la migración (idempotente).
        $caja1Id = DB::table('cajas_fisicas')
            ->where('nombre', 'Caja 1')
            ->value('id');

        $caja1Id ??= DB::table('cajas_fisicas')->insertGetId([
            'nombre' => 'Caja 1',
            'activa' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('cajas')->whereNull('caja_fisica_id')->update(['caja_fisica_id' => $caja1Id]);

        $sesionHistorica->refresh();
        $this->assertSame($caja1Id, $sesionHistorica->caja_fisica_id);

        // La venta sigue apuntando a la sesión original (no se pierde el caja_id).
        $this->assertDatabaseHas('ventas', ['id' => $venta->id, 'caja_id' => $sesionHistorica->id]);
        $this->assertSame($sesionHistorica->id, $venta->refresh()->caja_id);

        // El movimiento sigue asociado a la sesión original.
        $this->assertDatabaseHas('movimientos_caja', ['id' => $movimiento->id, 'caja_id' => $sesionHistorica->id]);
        $this->assertSame($sesionHistorica->id, $movimiento->refresh()->caja_id);
    }

    public function test_el_seed_crea_caja_uno_y_caja_dos_y_es_idempotente(): void
    {
        $this->seed(CajaFisicaSeeder::class);
        $this->seed(CajaFisicaSeeder::class);

        $nombres = CajaFisica::orderBy('nombre')->pluck('nombre')->all();
        $this->assertContains('Caja 1', $nombres);
        $this->assertContains('Caja 2', $nombres);
        $this->assertSame(2, CajaFisica::count());

        $this->assertSame(1, CajaFisica::where('nombre', 'Caja 1')->count());
        $this->assertSame(1, CajaFisica::where('nombre', 'Caja 2')->count());
    }

    public function test_la_restriccion_global_anterior_ya_no_impide_dos_cajas_fisicas_abiertas(): void
    {
        $this->assertSame(
            0,
            DB::selectOne("SELECT count(*) AS c FROM pg_indexes WHERE indexname = 'cajas_unica_abierta'")->c,
        );
        $this->assertSame(
            1,
            DB::selectOne("SELECT count(*) AS c FROM pg_indexes WHERE indexname = 'cajas_fisica_abierta'")->c,
        );
    }

    public function test_abrir_sin_caja_fisica_asigna_una_disponible_automaticamente(): void
    {
        $usuario = $this->crearUsuario(Rol::CAJERO);
        $caja = app(CajaService::class)->abrir($usuario, 1000);

        $this->assertNotNull($caja->caja_fisica_id);
        $this->assertDatabaseHas('cajas', ['id' => $caja->id, 'estado' => EstadoCaja::ABIERTA->value]);
    }

    // ------------------------------------------------------------------ Helpers

    private function datosCaja(Usuario $usuario, CajaFisica $cajaFisica): array
    {
        return [
            'caja_fisica_id' => $cajaFisica->id,
            'usuario_abre_id' => $usuario->id,
            'estado' => EstadoCaja::ABIERTA,
            'monto_inicial' => 0,
            'abierta_en' => now(),
        ];
    }

    private function crearUsuario(string $nombreRol): Usuario
    {
        $rol = Rol::firstOrCreate(['nombre' => $nombreRol]);

        return Usuario::factory()->create(['rol_id' => $rol->id]);
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
}
